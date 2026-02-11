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
/*
 * Surface component — renders the planet grid using PixiJS.
 *
 * Texture sub-regions:  The grid sprite atlas is loaded via the PixiJS
 * Loader.  Sub-textures for individual tiles are created with:
 *
 *     new PIXI.Texture(loaderTextureObject, frameRectangle)
 *
 * The first argument MUST be the Loader-created Texture object (not its
 * .baseTexture).  PixiJS internally converts it via:
 *     if (baseTexture instanceof Texture) baseTexture = baseTexture.baseTexture;
 * But this exact call path is the ONLY one tested/proven to produce
 * correct UVs in PixiJS 5.3.x.  Passing a raw BaseTexture directly
 * caused "texture bleeding" (full atlas rendered in every tile) because
 * the constructor's frame→UV code path behaves differently.
 */
import {
    autoDetectRenderer, Container, Loader, Sprite, utils, Text, Texture
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
            loader: undefined,
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

    created() {
        utils.skipHello();
    },

    mounted() {
        this._destroyed = false;
        this._hasPlanetData = false;
        this._loading = false;

        EventBus.$on('planet-updated', this.planetUpdated);
        EventBus.$emit('planet-data-request');

        this.$nextTick(() => {
            if (!this._destroyed && !this._hasPlanetData) {
                this.fetchPlanetDirect();
            }
        });

        this._retryTimer = setTimeout(() => {
            if (!this._destroyed && !this._hasPlanetData) {
                this.fetchPlanetDirect();
            }
        }, 3000);
    },

    beforeDestroy() {
        this._destroyed = true;
        EventBus.$off('planet-updated', this.planetUpdated);
        if (this._retryTimer) clearTimeout(this._retryTimer);
        this.destroyPixi();
    },

    methods: {
        /**
         * Strip the origin from a URL to make it root-relative.
         * Prevents PixiJS Loader from adding crossOrigin='anonymous'.
         */
        toLocalPath(url) {
            if (!url) return url;
            try { return new URL(url, window.location.origin).pathname; }
            catch (e) { return url.replace(/^https?:\/\/[^/]+/, ''); }
        },

        background() {
            return this.backgroundTexture.replace('__resource__', this.planet.resource_id);
        },

        backgroundName() {
            return 'background_' + this.planet.resource_id;
        },

        rendererWidth() {
            var el = this.$refs.viewport;
            var w = el ? el.clientWidth : 0;
            return w > 1 ? w : this.width;
        },

        rendererHeight() {
            var el = this.$refs.viewport;
            var h = el ? el.clientHeight : 0;
            return h > 1 ? h : this.height;
        },

        /* ── data fetching ───────────────────────────────────── */

        fetchPlanetDirect() {
            if (this._fetchInFlight) return;
            this._fetchInFlight = true;

            axios.get('/api/planet').then(response => {
                this._fetchInFlight = false;
                if (!this._destroyed && response.data && response.data.id) {
                    this.planetUpdated(response.data);
                }
            }).catch(() => {
                this._fetchInFlight = false;
                if (!this._destroyed && !this._hasPlanetData) {
                    this.errorMessage = 'Planet data could not be loaded.';
                }
            });
        },

        planetUpdated(planet) {
            if (this._destroyed || !planet || !planet.resource_id) return;

            if (this._hasPlanetData && this.stage) {
                this.planet = planet;
                try { this.setup(); } catch (e) { /* keep old scene */ }
                return;
            }

            this._hasPlanetData = true;
            this.planet = planet;

            // CSS fallback background.
            if (this.$refs.viewport) {
                this.$refs.viewport.style.backgroundImage =
                    'url(' + this.toLocalPath(this.background()) + ')';
            }

            if (!this._loading && !this.stage) {
                this.initPixi();
            }
        },

        /* ── PixiJS init ─────────────────────────────────────── */

        initPixi() {
            if (this._destroyed || this._loading) return;
            this._loading = true;
            this.errorMessage = '';

            var bgUrl   = this.toLocalPath(this.background());
            var gridUrl = this.toLocalPath(this.gridTextureAtlas);

            this.loader = new Loader();

            this.loader.onError.add((err, ldr, res) => {
                console.error('[Surface] Loader error:', res && res.url, err);
            });

            // Add with crossOrigin='' — empty string is falsy so the
            // Loader's _determineCrossOrigin won't add 'anonymous'.
            this.loader.add(this.backgroundName(), bgUrl, { crossOrigin: '' });
            this.loader.add('grid', gridUrl, { crossOrigin: '' });

            this.loader.load((ldr, resources) => {
                this._loading = false;
                if (this._destroyed) return;

                var bgRes   = resources[this.backgroundName()];
                var gridRes = resources.grid;

                if (!bgRes || bgRes.error || !gridRes || gridRes.error) {
                    this.errorMessage = 'Textures could not be loaded.';
                    this.ready = true;
                    return;
                }

                try {
                    this.createRenderer();
                    this.setup();
                    this.align();
                    this.animate();
                } catch (err) {
                    console.error('[Surface] render error:', err);
                    this.errorMessage = 'Render error: ' + err.message;
                } finally {
                    this.ready = true;
                }
            });
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
                width: this.rendererWidth(),
                height: this.rendererHeight(),
                view: this.$refs.canvas,
                backgroundColor: 0x0b0e14
            });

            window.addEventListener('resize', this.resize);
        },

        /* ── scene setup ─────────────────────────────────────── */

        setup() {
            this.clearIntervals();
            this.container.removeChildren();

            // Background sprite.
            this.container.addChild(this.backgroundSprite());

            // Grid tile sprites.
            _.forEach(this.planet.grids, grid => {
                try {
                    this.container.addChild(this.gridSprite(grid));
                } catch (e) {
                    console.warn('[Surface] grid', grid.id, e.message);
                }
            });
        },

        backgroundSprite() {
            return new Sprite(
                this.loader.resources[this.backgroundName()].texture
            );
        },

        /**
         * Create a sub-texture from the grid atlas.
         *
         * CRITICAL:  The first argument to `new Texture()` MUST be the
         * Loader-created Texture object — NOT its .baseTexture.  In
         * PixiJS 5.3 the constructor does:
         *
         *   if (baseTexture instanceof Texture) {
         *       baseTexture = baseTexture.baseTexture;
         *   }
         *
         * This code path + the subsequent frame setter logic is the
         * ONLY combination that reliably produces correct UVs.
         * Passing a raw BaseTexture directly skips this branch and
         * causes "texture bleeding" (full atlas in every tile).
         */
        gridTexture(grid) {
            var frame = Sprites.plain;

            try {
                if (grid.construction) {
                    frame = Sprites.constructions[grid.construction.building_id] || frame;
                } else if (grid.type === 1) {
                    if (grid.building_id) {
                        var bs = Sprites.buildings[grid.building_id];
                        if (bs && typeof bs === 'object' && !bs.width) {
                            frame = bs[this.planet.resource_id] || frame;
                        } else {
                            frame = bs || frame;
                        }
                    } else {
                        frame = Sprites.resources[this.planet.resource_id] || frame;
                    }
                } else if (grid.building_id) {
                    frame = Sprites.buildings[grid.building_id] || frame;
                }
            } catch (e) {
                frame = Sprites.plain;
            }

            // Pass the Loader's TEXTURE object (not baseTexture!) as
            // first arg.  This is how the original code worked and is
            // the only reliable path in PixiJS 5.3 for sub-textures.
            return new Texture(
                this.loader.resources.grid.texture,
                frame
            );
        },

        gridSprite(grid) {
            var sprite = new Sprite(this.gridTexture(grid));

            sprite.interactive = true;
            sprite.buttonMode  = true;
            sprite.hitArea     = Sprites.hitArea;

            sprite.x = this.gridX(grid);
            sprite.y = this.gridY(grid);

            sprite.on('mouseover', () => { sprite.alpha = 0.6; });
            sprite.on('mouseout',  () => { sprite.alpha = 1; });
            sprite.on('pointerup', () => {
                if (this.dragged <= this.clickTreshold) {
                    this.gridClick(grid);
                }
            });

            this.gridLevel(grid, sprite);
            this.gridRemaining(grid, sprite);

            return sprite;
        },

        gridClick(grid) {
            EventBus.$emit(
                grid.building_id ? 'building-click' : 'grid-click',
                grid
            );
        },

        /* ── layout ──────────────────────────────────────────── */

        gridX(grid) {
            return (grid.x - grid.y + 4) * 162 + (this.width - 1608) / 2;
        },

        gridY(grid) {
            return (grid.x + grid.y) * 81 + (this.height - 888) / 2;
        },

        containerScale() {
            var w = this.rendererWidth();
            var h = this.rendererHeight();

            var current = _.findLast(
                [{minWidth:1,maxHeight:592,ratio:0.64},
                 {minWidth:992,maxHeight:765,ratio:0.827},
                 {minWidth:1200,maxHeight:false,ratio:1}],
                bp => bp.minWidth <= w
            );
            if (!current) return 1;
            if (current.maxHeight === false || current.maxHeight >= h) return current.ratio;

            current = _.findLast(
                [{minWidth:1,maxHeight:592,ratio:0.64},
                 {minWidth:992,maxHeight:765,ratio:0.827}],
                bp => bp.maxHeight >= h
            );
            return current ? current.ratio : 1;
        },

        align() {
            this.container.position.x = (this.renderer.width  - this.container.width)  / 2;
            this.container.position.y = (this.renderer.height - this.container.height) / 2;
        },

        resize() {
            if (!this.renderer) return;
            this.renderer.resize(this.rendererWidth(), this.rendererHeight());
            this.container.scale.set(this.containerScale());
            this.align();
        },

        /* ── render loop ─────────────────────────────────────── */

        animate() {
            if (this._destroyed || !this.renderer || !this.stage || !this.container) return;

            this.animationFrame = requestAnimationFrame(this.animate);

            var minX = this.renderer.width  - this.container.width;
            var minY = this.renderer.height - this.container.height;

            if (this.container.position.x < minX) this.container.position.x = minX;
            if (this.container.position.y < minY) this.container.position.y = minY;
            if (this.container.position.x > 0) this.container.position.x = 0;
            if (this.container.position.y > 0) this.container.position.y = 0;

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
            var prevX = this.container.position.x;
            var prevY = this.container.position.y;
            this.container.position.x = pos.x - this.dragStartX;
            this.container.position.y = pos.y - this.dragStartY;
            this.dragged += Math.abs(prevX - this.container.position.x)
                          + Math.abs(prevY - this.container.position.y);
        },

        onDragEnd() {
            this.isDragging = false;
        },

        /* ── text helpers ────────────────────────────────────── */

        gridLevel(grid, sprite) {
            if (!grid.level) return;
            var text = new Text(grid.level, this.textStyle);
            text.position.x = (sprite.width - text.width) / 2;
            text.position.y = sprite.height - 50;
            sprite.addChild(text);
        },

        gridRemaining(grid, sprite) {
            var remaining, textStyle;

            if (grid.construction) {
                remaining = grid.construction.remaining;
                textStyle = this.textStyle;
            } else if (grid.training) {
                remaining = grid.training.remaining;
                textStyle = _.assignIn({}, this.textStyle, { fill: '#ebb237' });
            } else if (grid.upgrade) {
                remaining = grid.upgrade.remaining;
                textStyle = this.textStyle;
            }

            if (!remaining) return;

            var text = new Text(Filters.timer(remaining), textStyle);
            text.position.x = (sprite.width - text.width) / 2;
            text.position.y = (sprite.height - text.height) / 2;
            sprite.addChild(text);

            var interval = setInterval(() => {
                remaining -= 1;
                text.text = Filters.timer(remaining);
                if (!remaining) clearInterval(interval);
            }, 1000);

            this.intervals.push(interval);
        },

        /* ── cleanup ─────────────────────────────────────────── */

        clearIntervals() {
            _.forEach(this.intervals, iv => clearInterval(iv));
            this.intervals = [];
        },

        destroyPixi() {
            this._loading = false;
            this.clearIntervals();

            if (this.animationFrame) {
                cancelAnimationFrame(this.animationFrame);
                this.animationFrame = undefined;
            }
            if (this.renderer)  { this.renderer.destroy();  this.renderer  = undefined; }
            if (this.container) { this.container.destroy(true); this.container = undefined; }
            if (this.stage)     { this.stage.destroy(true);     this.stage     = undefined; }
            if (this.loader)    { this.loader.destroy();        this.loader    = undefined; }

            utils.destroyTextureCache();
            window.removeEventListener('resize', this.resize);
        }
    }
};
</script>
