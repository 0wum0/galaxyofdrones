<template>
    <div class="surface-viewport" ref="viewport">
        <canvas ref="canvas" class="surface"></canvas>
        <div v-if="!ready" class="surface-loading">
            <div class="surface-spinner"></div>
        </div>
        <p v-if="errorMessage" class="surface-error-text">{{ errorMessage }}</p>
    </div>
</template>
<script>
import {
    autoDetectRenderer, BaseTexture, Container, Sprite,
    Text, Texture, utils
} from 'pixi.js';

import { EventBus } from '../event-bus';
import Filters from './Filters';
import Sprites from './Sprites';

utils.skipHello();

/**
 * Load image with crossOrigin so canvas won't be tainted.
 */
function loadImg(url) {
    return new Promise(function (resolve, reject) {
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () { resolve(img); };
        img.onerror = function () { reject(new Error('Failed: ' + url)); };
        img.src = url;
    });
}

/**
 * Cut a sub-region from a source image via Canvas 2D.
 *
 * Returns a fresh PIXI.Texture that bypasses the PixiJS texture
 * cache entirely (no shared BaseTexture, no UV framing bugs).
 *
 * CRITICAL: create BaseTexture directly from canvas — do NOT use
 * Texture.from(canvas) which caches by canvas _pixiId and can
 * return stale/wrong textures.
 */
function cutSprite(img, rect) {
    var c = document.createElement('canvas');
    c.width  = rect.width;
    c.height = rect.height;
    c.getContext('2d').drawImage(
        img,
        rect.x, rect.y, rect.width, rect.height,
        0, 0, rect.width, rect.height
    );
    // Bypass PixiJS cache: raw BaseTexture + Texture.
    return new Texture(new BaseTexture(c));
}

/**
 * Resolve the correct Rectangle from Sprites.js.
 * Handles the nested object case (buildings[2] has per-resource sub-map).
 */
function resolveFrame(grid, resourceId) {
    var r = Sprites.plain;

    try {
        if (grid.construction) {
            r = Sprites.constructions[grid.construction.building_id] || r;
        } else if (grid.type === 1) {
            // Resource slot.
            if (grid.building_id) {
                var bs = Sprites.buildings[grid.building_id];
                if (bs && typeof bs.width === 'number') {
                    r = bs;
                } else if (bs && typeof bs === 'object') {
                    r = bs[resourceId] || bs[1] || r;
                }
            } else {
                r = Sprites.resources[resourceId] || r;
            }
        } else if (grid.building_id) {
            var b = Sprites.buildings[grid.building_id];
            if (b && typeof b.width === 'number') {
                r = b;
            } else if (b && typeof b === 'object') {
                r = b[resourceId] || b[1] || r;
            }
        }
    } catch (e) {
        r = Sprites.plain;
    }

    if (!r || typeof r.width !== 'number') r = Sprites.plain;
    return r;
}

export default {
    props: {
        width:             { type: Number, required: true },
        height:            { type: Number, required: true },
        backgroundTexture: { type: String, required: true },
        gridTextureAtlas:  { type: String, required: true }
    },

    data() {
        return {
            animationFrame: undefined,
            clickTreshold: 5,
            isDragging: false,
            dragStartX: 0,
            dragStartY: 0,
            dragged: 0,
            container: undefined,
            intervals: [],
            renderer: undefined,
            stage: undefined,
            ready: false,
            errorMessage: '',
            planet: { resource_id: undefined, grids: [] },
            textStyle: {
                fontFamily: 'Exo 2', fontSize: '14px', fill: '#fff',
                align: 'center', stroke: '#0e141c', strokeThickness: 4
            }
        };
    },

    mounted() {
        this._destroyed = false;
        this._hasPlanetData = false;
        this._loading = false;

        EventBus.$on('planet-updated', this.planetUpdated);
        EventBus.$emit('planet-data-request');

        this.$nextTick(() => {
            if (!this._destroyed && !this._hasPlanetData) this.fetchPlanetDirect();
        });
        this._retryTimer = setTimeout(() => {
            if (!this._destroyed && !this._hasPlanetData) this.fetchPlanetDirect();
        }, 3000);
    },

    beforeDestroy() {
        this._destroyed = true;
        EventBus.$off('planet-updated', this.planetUpdated);
        if (this._retryTimer) clearTimeout(this._retryTimer);
        this.destroyPixi();
    },

    methods: {
        toLocalPath(url) {
            if (!url) return url;
            try { return new URL(url, window.location.origin).pathname; }
            catch (e) { return url.replace(/^https?:\/\/[^/]+/, ''); }
        },

        bgImageUrl() {
            return this.toLocalPath(
                this.backgroundTexture.replace('__resource__', this.planet.resource_id)
            );
        },

        gridImageUrl() {
            return this.toLocalPath(this.gridTextureAtlas);
        },

        vpW() {
            var el = this.$refs.viewport;
            return el && el.clientWidth > 1 ? el.clientWidth : this.width;
        },

        vpH() {
            var el = this.$refs.viewport;
            return el && el.clientHeight > 1 ? el.clientHeight : this.height;
        },

        /* ── data ────────────────────────────────────────────── */

        fetchPlanetDirect() {
            if (this._fetchInFlight) return;
            this._fetchInFlight = true;

            axios.get('/api/planet').then(response => {
                this._fetchInFlight = false;
                if (!this._destroyed && response.data && response.data.id)
                    this.planetUpdated(response.data);
            }).catch(() => {
                this._fetchInFlight = false;
                if (!this._destroyed && !this._hasPlanetData)
                    this.errorMessage = 'Planet data could not be loaded.';
            });
        },

        planetUpdated(planet) {
            if (this._destroyed || !planet || !planet.resource_id) return;

            if (this._hasPlanetData && this.stage) {
                this.planet = planet;
                try { this.buildGrid(); } catch (e) { /* keep old */ }
                return;
            }

            this._hasPlanetData = true;
            this.planet = planet;

            if (this.$refs.viewport)
                this.$refs.viewport.style.backgroundImage = 'url(' + this.bgImageUrl() + ')';

            if (!this._loading && !this.stage) this.setupPixi();
        },

        /* ═══════════════════════════════════════════════════════
         *  setupPixi
         * ═══════════════════════════════════════════════════════ */

        async setupPixi() {
            if (this._destroyed || this._loading) return;
            this._loading = true;
            this.errorMessage = '';

            try {
                // Load images with crossOrigin to prevent tainted canvas.
                var bgImg   = await loadImg(this.bgImageUrl());
                var gridImg = await loadImg(this.gridImageUrl());

                if (this._destroyed) return;

                this._bgImg   = bgImg;
                this._gridImg = gridImg;

                // Create renderer.
                this.stage = new Container();
                this.container = new Container();
                this.container.interactive = true;
                this.container.interactiveChildren = true;
                this.container.scale.set(this.containerScale());

                this.container.on('pointerdown', this.onDragStart);
                this.container.on('pointermove', this.onDragMove);
                this.container.on('pointerup', this.onDragEnd);
                this.container.on('pointerupoutside', this.onDragEnd);

                this.stage.addChild(this.container);

                this.renderer = autoDetectRenderer({
                    width: this.vpW(),
                    height: this.vpH(),
                    view: this.$refs.canvas,
                    backgroundColor: 0x0b0e14
                });

                window.addEventListener('resize', this.onResize);

                // Build scene.
                this.buildGrid();
                this.align();
                this.animate();

            } catch (err) {
                console.error('[Surface] setupPixi error:', err);
                this.errorMessage = 'Surface error: ' + err.message;
            } finally {
                this._loading = false;
                this.ready = true;
            }
        },

        /* ═══════════════════════════════════════════════════════
         *  buildGrid
         * ═══════════════════════════════════════════════════════ */

        buildGrid() {
            this.clearIntervals();
            this.container.removeChildren();

            // Layer 0: background.
            if (this._bgImg) {
                var bgTex = new Texture(new BaseTexture(this._bgImg));
                this.container.addChild(new Sprite(bgTex));
            }

            // Layer 1+: grid tiles.
            var grids = this.planet.grids;
            if (!grids || !grids.length || !this._gridImg) return;

            // ── Normalize grid coordinates ──────────────────────
            // The API returns absolute starmap coordinates (e.g.
            // x=20, y=20) which produce huge pixel values when
            // multiplied.  We need 0-based relative indices.
            var minX = grids[0].x, minY = grids[0].y;
            for (var i = 1; i < grids.length; i++) {
                if (grids[i].x < minX) minX = grids[i].x;
                if (grids[i].y < minY) minY = grids[i].y;
            }
            this._gridOffsetX = minX;
            this._gridOffsetY = minY;

            for (var j = 0; j < grids.length; j++) {
                try {
                    this.container.addChild(this.makeSlot(grids[j]));
                } catch (e) {
                    console.warn('[Surface] slot', j, e.message);
                }
            }
        },

        makeSlot(grid) {
            var rect   = resolveFrame(grid, this.planet.resource_id);
            var tex    = cutSprite(this._gridImg, rect);
            var sprite = new Sprite(tex);

            // Normalize to 0-based indices.
            var relX = grid.x - this._gridOffsetX;
            var relY = grid.y - this._gridOffsetY;

            // Isometric diamond layout on the 1920×1080 canvas.
            // +4 centers a 5×5 grid; (width-1608)/2 and (height-888)/2
            // center the diamond within the background image.
            sprite.x = (relX - relY + 4) * 162 + (this.width - 1608) / 2;
            sprite.y = (relX + relY) * 81 + (this.height - 888) / 2;

            // Interaction.
            sprite.interactive = true;
            sprite.buttonMode  = true;
            sprite.hitArea     = Sprites.hitArea;

            sprite.on('pointerup', () => {
                if (this.dragged <= this.clickTreshold) {
                    EventBus.$emit(
                        grid.building_id ? 'building-click' : 'grid-click',
                        grid
                    );
                }
            });
            sprite.on('mouseover', () => { sprite.alpha = 0.6; });
            sprite.on('mouseout',  () => { sprite.alpha = 1; });

            if (grid.level) {
                var t = new Text(grid.level, this.textStyle);
                t.position.x = (sprite.width - t.width) / 2;
                t.position.y = sprite.height - 50;
                sprite.addChild(t);
            }

            this.addTimer(grid, sprite);
            return sprite;
        },

        addTimer(grid, sprite) {
            var remaining, style;
            if (grid.construction)  { remaining = grid.construction.remaining; style = this.textStyle; }
            else if (grid.training) { remaining = grid.training.remaining; style = _.assignIn({}, this.textStyle, { fill: '#ebb237' }); }
            else if (grid.upgrade)  { remaining = grid.upgrade.remaining; style = this.textStyle; }
            if (!remaining) return;

            var text = new Text(Filters.timer(remaining), style);
            text.position.x = (sprite.width - text.width) / 2;
            text.position.y = (sprite.height - text.height) / 2;
            sprite.addChild(text);

            var iv = setInterval(() => {
                remaining -= 1;
                text.text = Filters.timer(remaining);
                if (!remaining) clearInterval(iv);
            }, 1000);
            this.intervals.push(iv);
        },

        /* ── layout ──────────────────────────────────────────── */

        containerScale() {
            var w = this.vpW(), h = this.vpH();
            if (w >= 1200) return 1;
            if (w >= 992 && h >= 765) return 0.827;
            return 0.64;
        },

        align() {
            if (!this.renderer || !this.container) return;
            this.container.position.set(
                (this.renderer.width  - this.container.width)  / 2,
                (this.renderer.height - this.container.height) / 2
            );
        },

        onResize() {
            if (!this.renderer) return;
            this.renderer.resize(this.vpW(), this.vpH());
            this.container.scale.set(this.containerScale());
            this.align();
        },

        /* ── render loop ─────────────────────────────────────── */

        animate() {
            if (this._destroyed || !this.renderer || !this.stage || !this.container) return;
            this.animationFrame = requestAnimationFrame(this.animate);

            var minX = this.renderer.width  - this.container.width;
            var minY = this.renderer.height - this.container.height;
            var p = this.container.position;
            if (p.x < minX) p.x = minX;
            if (p.y < minY) p.y = minY;
            if (p.x > 0) p.x = 0;
            if (p.y > 0) p.y = 0;

            this.renderer.render(this.stage);
        },

        /* ── pan / drag ──────────────────────────────────────── */

        onDragStart(e) {
            var p = e.data.getLocalPosition(this.container.parent);
            this.dragStartX = p.x - this.container.position.x;
            this.dragStartY = p.y - this.container.position.y;
            this.isDragging = true;
            this.dragged = 0;
        },

        onDragMove(e) {
            if (!this.isDragging) return;
            var p = e.data.getLocalPosition(this.container.parent);
            var ox = this.container.position.x, oy = this.container.position.y;
            this.container.position.x = p.x - this.dragStartX;
            this.container.position.y = p.y - this.dragStartY;
            this.dragged += Math.abs(ox - this.container.position.x)
                          + Math.abs(oy - this.container.position.y);
        },

        onDragEnd() { this.isDragging = false; },

        /* ── cleanup ─────────────────────────────────────────── */

        clearIntervals() {
            _.forEach(this.intervals, iv => clearInterval(iv));
            this.intervals = [];
        },

        destroyPixi() {
            this._loading = false;
            this.clearIntervals();
            if (this.animationFrame) { cancelAnimationFrame(this.animationFrame); this.animationFrame = undefined; }
            if (this.renderer)  { this.renderer.destroy();  this.renderer  = undefined; }
            if (this.container) { this.container.destroy(true); this.container = undefined; }
            if (this.stage)     { this.stage.destroy(true);     this.stage     = undefined; }
            this._bgImg = this._gridImg = undefined;
            utils.destroyTextureCache();
            window.removeEventListener('resize', this.onResize);
        }
    }
};
</script>
