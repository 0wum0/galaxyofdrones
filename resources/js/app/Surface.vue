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
    autoDetectRenderer, BaseTexture, Container, Graphics,
    Polygon, Rectangle, Sprite, Text, Texture, utils
} from 'pixi.js';

import { EventBus } from '../event-bus';
import Filters from './Filters';
import Sprites from './Sprites';

utils.skipHello();

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

        /* ── data fetching ───────────────────────────────────── */

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
                try { this.buildGrid(); } catch (e) { /* keep old scene */ }
                return;
            }

            this._hasPlanetData = true;
            this.planet = planet;

            // CSS fallback.
            if (this.$refs.viewport)
                this.$refs.viewport.style.backgroundImage = 'url(' + this.bgImageUrl() + ')';

            if (!this._loading && !this.stage) this.setupPixi();
        },

        /* ════════════════════════════════════════════════════════
         *  CORE:  setupPixi  —  NO Loader, manual BaseTexture.from
         * ════════════════════════════════════════════════════════ */

        async setupPixi() {
            if (this._destroyed || this._loading) return;
            this._loading = true;
            this.errorMessage = '';

            try {
                // ── 1) Load images via BaseTexture.from ─────────────
                // This is a direct Image() load — no PixiJS Loader.
                var bgBase   = BaseTexture.from(this.bgImageUrl());
                var gridBase = BaseTexture.from(this.gridImageUrl());

                // Wait until both are valid (loaded into GPU memory).
                if (!bgBase.valid) {
                    await new Promise(function (resolve) {
                        bgBase.once('loaded', resolve);
                        bgBase.once('error', function () { resolve(); });
                    });
                }
                if (!gridBase.valid) {
                    await new Promise(function (resolve) {
                        gridBase.once('loaded', resolve);
                        gridBase.once('error', function () { resolve(); });
                    });
                }

                if (this._destroyed) return;

                // ── 2) Create master textures ───────────────────────
                // The "atlas texture" wraps the BaseTexture.  Sub-textures
                // are then created from this atlas — this is the proven
                // pattern that produces correct UV coordinates in PixiJS 5.
                this._bgTexture    = new Texture(bgBase);
                this._gridAtlasTex = new Texture(gridBase);

                // ── 3) Create renderer + scene ──────────────────────
                this.createRenderer();
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

        createRenderer() {
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
        },

        /* ════════════════════════════════════════════════════════
         *  buildGrid  —  Sprites.js rectangles + atlas master tex
         * ════════════════════════════════════════════════════════ */

        buildGrid() {
            this.clearIntervals();
            this.container.removeChildren();

            // Layer 0: planet background.
            if (this._bgTexture && this._bgTexture.valid) {
                this.container.addChild(new Sprite(this._bgTexture));
            }

            // Layer 1+: grid tiles.
            var grids = this.planet.grids;
            if (!grids || !grids.length) return;

            for (var i = 0; i < grids.length; i++) {
                try {
                    this.container.addChild(this.makeSlot(grids[i]));
                } catch (e) {
                    console.warn('[Surface] slot', grids[i].id, e.message);
                }
            }
        },

        /* ── single grid slot ────────────────────────────────── */

        makeSlot(grid) {
            var rect = this.pickRect(grid);

            // Create sub-texture:  new Texture(masterAtlasTexture, frame)
            // The first arg is the master Texture (NOT a BaseTexture).
            // PixiJS 5 internally does:
            //   if (baseTexture instanceof Texture) baseTexture = baseTexture.baseTexture;
            // followed by correct frame/UV setup.
            var tex = new Texture(this._gridAtlasTex, rect);
            var sprite = new Sprite(tex);

            sprite.x = this.gridX(grid);
            sprite.y = this.gridY(grid);

            // Interaction — uses the hitArea polygon from Sprites.js
            // for precise diamond-shaped click detection.
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

            // Level number.
            if (grid.level) {
                var lvl = new Text(grid.level, this.textStyle);
                lvl.position.x = (sprite.width - lvl.width) / 2;
                lvl.position.y = sprite.height - 50;
                sprite.addChild(lvl);
            }

            // Countdown timer.
            this.addTimer(grid, sprite);

            return sprite;
        },

        /**
         * Pick the correct Rectangle from Sprites.js for a grid slot.
         */
        pickRect(grid) {
            // Default: empty plain tile.
            var r = Sprites.plain;

            try {
                if (grid.construction) {
                    // Under construction → ghost/outline sprite.
                    r = Sprites.constructions[grid.construction.building_id] || r;

                } else if (grid.type === 1) {
                    // Resource slot.
                    if (grid.building_id) {
                        var bs = Sprites.buildings[grid.building_id];
                        // Building 2 is special — has per-resource sub-map.
                        if (bs && typeof bs === 'object' && !bs.width) {
                            r = bs[this.planet.resource_id] || r;
                        } else {
                            r = bs || r;
                        }
                    } else {
                        // Empty resource slot → resource crystal.
                        r = Sprites.resources[this.planet.resource_id] || r;
                    }

                } else if (grid.building_id) {
                    // Normal built slot.
                    r = Sprites.buildings[grid.building_id] || r;
                }
            } catch (e) {
                r = Sprites.plain;
            }

            return r;
        },

        addTimer(grid, sprite) {
            var remaining, style;

            if (grid.construction) {
                remaining = grid.construction.remaining;
                style = this.textStyle;
            } else if (grid.training) {
                remaining = grid.training.remaining;
                style = _.assignIn({}, this.textStyle, { fill: '#ebb237' });
            } else if (grid.upgrade) {
                remaining = grid.upgrade.remaining;
                style = this.textStyle;
            }

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

        gridX(g) { return (g.x - g.y + 4) * 162 + (this.width - 1608) / 2; },
        gridY(g) { return (g.x + g.y) * 81 + (this.height - 888) / 2; },

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
            var pos = e.data.getLocalPosition(this.container.parent);
            this.dragStartX = pos.x - this.container.position.x;
            this.dragStartY = pos.y - this.container.position.y;
            this.isDragging = true;
            this.dragged = 0;
        },

        onDragMove(e) {
            if (!this.isDragging) return;
            var pos = e.data.getLocalPosition(this.container.parent);
            var px = this.container.position.x;
            var py = this.container.position.y;
            this.container.position.x = pos.x - this.dragStartX;
            this.container.position.y = pos.y - this.dragStartY;
            this.dragged += Math.abs(px - this.container.position.x)
                          + Math.abs(py - this.container.position.y);
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

            this._bgTexture = undefined;
            this._gridAtlasTex = undefined;
            utils.destroyTextureCache();
            window.removeEventListener('resize', this.onResize);
        }
    }
};
</script>
