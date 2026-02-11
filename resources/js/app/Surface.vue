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
import * as PIXI from 'pixi.js';
import { EventBus } from '../event-bus';
import Filters from './Filters';
import Sprites from './Sprites';

PIXI.utils.skipHello();

export default {
    props: {
        width:             { type: Number, required: true },
        height:            { type: Number, required: true },
        backgroundTexture: { type: String, required: true },
        gridTextureAtlas:  { type: String, required: true }
    },

    data: function () {
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
            loader: undefined,
            ready: false,
            errorMessage: '',
            planet: { resource_id: undefined, grids: [] },
            textStyle: {
                fontFamily: 'Exo 2', fontSize: '14px', fill: '#fff',
                align: 'center', stroke: '#0e141c', strokeThickness: 4
            }
        };
    },

    mounted: function () {
        this._destroyed = false;
        this._hasPlanetData = false;
        this._loading = false;

        EventBus.$on('planet-updated', this.onPlanetData);
        EventBus.$emit('planet-data-request');

        var self = this;
        this.$nextTick(function () {
            if (!self._destroyed && !self._hasPlanetData) self.fetchPlanet();
        });
        this._timer = setTimeout(function () {
            if (!self._destroyed && !self._hasPlanetData) self.fetchPlanet();
        }, 3000);
    },

    beforeDestroy: function () {
        this._destroyed = true;
        EventBus.$off('planet-updated', this.onPlanetData);
        clearTimeout(this._timer);
        this.teardown();
    },

    methods: {
        /**
         * Convert absolute URL to root-relative path so the PixiJS Loader
         * treats it as same-origin (no crossOrigin='anonymous' header).
         */
        localUrl: function (url) {
            if (!url) return url;
            try { return new URL(url, location.origin).pathname; }
            catch (e) { return url.replace(/^https?:\/\/[^\/]+/, ''); }
        },

        bgUrl: function () {
            return this.localUrl(
                this.backgroundTexture.replace('__resource__', this.planet.resource_id)
            );
        },

        vpW: function () {
            var w = this.$refs.viewport ? this.$refs.viewport.clientWidth : 0;
            return w > 1 ? w : this.width;
        },

        vpH: function () {
            var h = this.$refs.viewport ? this.$refs.viewport.clientHeight : 0;
            return h > 1 ? h : this.height;
        },

        /* ── data fetching ───────────────────────────────────── */
        fetchPlanet: function () {
            if (this._fetchInFlight) return;
            this._fetchInFlight = true;
            var self = this;
            axios.get('/api/planet').then(function (r) {
                self._fetchInFlight = false;
                if (!self._destroyed && r.data && r.data.id) self.onPlanetData(r.data);
            }).catch(function () {
                self._fetchInFlight = false;
                if (!self._destroyed && !self._hasPlanetData)
                    self.errorMessage = 'Planet data could not be loaded.';
            });
        },

        onPlanetData: function (planet) {
            if (this._destroyed || !planet || !planet.resource_id) return;

            if (this._hasPlanetData && this.stage) {
                this.planet = planet;
                try { this.buildScene(); } catch (e) { /* keep old scene */ }
                return;
            }

            this._hasPlanetData = true;
            this.planet = planet;

            // CSS fallback background.
            if (this.$refs.viewport)
                this.$refs.viewport.style.backgroundImage = 'url(' + this.bgUrl() + ')';

            if (!this._loading && !this.stage) this.initPixi();
        },

        /* ── PixiJS init ─────────────────────────────────────── */
        /**
         * Use the PixiJS Loader with root-relative URLs.
         *
         * The Loader creates BaseTexture + Texture objects internally and
         * handles sprite-atlas frame sub-regions correctly (the previous
         * "native Image + new BaseTexture" approach produced textures
         * where the frame/UV was NOT applied, causing the entire sprite
         * sheet to render in every grid slot).
         *
         * Root-relative URLs (via localUrl()) prevent the Loader from
         * adding crossOrigin='anonymous' which caused CORS/tainted-canvas
         * failures in earlier iterations.
         */
        initPixi: function () {
            if (this._destroyed || this._loading) return;
            this._loading = true;
            this.errorMessage = '';

            var bgKey   = 'bg_' + this.planet.resource_id;
            var bgUrl   = this.bgUrl();
            var gridUrl = this.localUrl(this.gridTextureAtlas);

            this.loader = new PIXI.Loader();

            // Add resources — crossOrigin explicitly empty so the Loader
            // does NOT send 'anonymous' (which triggers CORS).
            this.loader.add({ name: bgKey,  url: bgUrl,   crossOrigin: '' });
            this.loader.add({ name: 'grid', url: gridUrl, crossOrigin: '' });

            var self = this;
            this.loader.load(function (_, resources) {
                self._loading = false;
                if (self._destroyed) return;

                var bgRes   = resources[bgKey];
                var gridRes = resources.grid;

                if (!bgRes || bgRes.error || !gridRes || gridRes.error) {
                    console.error('[Surface] texture load failed',
                        bgRes && bgRes.error, gridRes && gridRes.error);
                    self.errorMessage = 'Textures could not be loaded.';
                    self.ready = true;
                    return;
                }

                try {
                    self._bgTexture   = bgRes.texture;
                    self._gridTexture = gridRes.texture;
                    self.createRenderer();
                    self.buildScene();
                    self.startLoop();
                    self.ready = true;
                } catch (err) {
                    console.error('[Surface] init error:', err);
                    self.errorMessage = 'Renderer error: ' + err.message;
                    self.ready = true;
                }
            });
        },

        createRenderer: function () {
            this.stage = new PIXI.Container();
            this.container = new PIXI.Container();
            this.container.interactive = true;
            this.container.interactiveChildren = true;

            var evMap = {
                pointerdown: this.onDragStart, pointermove: this.onDragMove,
                pointerup: this.onDragEnd, pointerupoutside: this.onDragEnd,
                touchstart: this.onDragStart, touchmove: this.onDragMove,
                touchend: this.onDragEnd, touchendoutside: this.onDragEnd
            };
            for (var ev in evMap) this.container.on(ev, evMap[ev]);

            this.stage.addChild(this.container);

            this.renderer = PIXI.autoDetectRenderer({
                width: this.vpW(), height: this.vpH(),
                view: this.$refs.canvas,
                backgroundColor: 0x0b0e14
            });

            window.addEventListener('resize', this.onResize);
        },

        /* ── scene ───────────────────────────────────────────── */
        buildScene: function () {
            this.clearIntervals();
            this.container.removeChildren();
            this.container.scale.set(this.containerScale());

            // 1) Background (layer 0).
            this.container.addChild(new PIXI.Sprite(this._bgTexture));

            // 2) Grid slots (layer 1+).
            var grids = this.planet.grids;
            if (grids && grids.length) {
                for (var i = 0; i < grids.length; i++) {
                    try {
                        this.container.addChild(this.makeSlot(grids[i]));
                    } catch (e) {
                        console.warn('[Surface] slot', i, e.message);
                    }
                }
            }

            this.alignContainer();
        },

        /**
         * Create a single grid slot sprite from the atlas.
         *
         * The key insight: Texture sub-regions are created via
         *   new PIXI.Texture(baseTexture, frameRectangle)
         * using the Loader-created baseTexture (this._gridTexture.baseTexture)
         * which has correct dimensions and is already uploaded to the GPU.
         * The frame rectangles from Sprites.js are cloned to prevent any
         * shared-mutation issues.
         */
        makeSlot: function (grid) {
            var frame = this.pickFrame(grid);
            var tex = new PIXI.Texture(
                this._gridTexture.baseTexture,
                frame.clone()
            );
            var sprite = new PIXI.Sprite(tex);

            sprite.x = this.gridX(grid);
            sprite.y = this.gridY(grid);
            sprite.interactive = true;
            sprite.buttonMode  = true;
            sprite.hitArea     = Sprites.hitArea;

            var self = this;
            sprite.on('pointerdown', function () { self._slotDown = true; });
            sprite.on('pointerup', function () {
                if (self._slotDown && self.dragged <= self.clickTreshold) {
                    self.gridClick(grid);
                }
                self._slotDown = false;
            });
            sprite.on('pointerover', function () { sprite.alpha = 0.65; });
            sprite.on('pointerout',  function () { sprite.alpha = 1; });

            if (grid.level) {
                var t = new PIXI.Text(grid.level, this.textStyle);
                t.x = (sprite.width - t.width) / 2;
                t.y = sprite.height - 50;
                sprite.addChild(t);
            }

            this.addTimer(grid, sprite);
            return sprite;
        },

        pickFrame: function (grid) {
            var f = Sprites.plain;
            try {
                if (grid.construction) {
                    f = Sprites.constructions[grid.construction.building_id] || f;
                } else if (grid.type === 1) {
                    if (grid.building_id) {
                        var bs = Sprites.buildings[grid.building_id];
                        if (bs && typeof bs === 'object' && !bs.width) {
                            f = bs[this.planet.resource_id] || f;
                        } else { f = bs || f; }
                    } else {
                        f = Sprites.resources[this.planet.resource_id] || f;
                    }
                } else if (grid.building_id) {
                    f = Sprites.buildings[grid.building_id] || f;
                }
            } catch (e) { /* default */ }
            return f;
        },

        addTimer: function (grid, slot) {
            var remaining, style;
            if (grid.construction)  { remaining = grid.construction.remaining; style = this.textStyle; }
            else if (grid.training) { remaining = grid.training.remaining; style = _.assignIn({}, this.textStyle, { fill: '#ebb237' }); }
            else if (grid.upgrade)  { remaining = grid.upgrade.remaining; style = this.textStyle; }
            if (!remaining) return;

            var text = new PIXI.Text(Filters.timer(remaining), style);
            text.x = (slot.width - text.width) / 2;
            text.y = (slot.height - text.height) / 2;
            slot.addChild(text);

            var iv = setInterval(function () {
                remaining -= 1;
                text.text = Filters.timer(remaining);
                if (!remaining) clearInterval(iv);
            }, 1000);
            this.intervals.push(iv);
        },

        gridClick: function (grid) {
            EventBus.$emit(grid.building_id ? 'building-click' : 'grid-click', grid);
        },

        /* ── layout ──────────────────────────────────────────── */
        gridX: function (g) { return (g.x - g.y + 4) * 162 + (this.width - 1608) / 2; },
        gridY: function (g) { return (g.x + g.y) * 81 + (this.height - 888) / 2; },

        containerScale: function () {
            var w = this.vpW(), h = this.vpH();
            if (w >= 1200) return 1;
            if (w >= 992 && h >= 765) return 0.827;
            return 0.64;
        },

        alignContainer: function () {
            if (!this.renderer || !this.container) return;
            this.container.position.set(
                (this.renderer.width  - this.container.width)  / 2,
                (this.renderer.height - this.container.height) / 2
            );
        },

        /* ── render loop ─────────────────────────────────────── */
        startLoop: function () {
            var self = this;
            (function loop() {
                if (self._destroyed || !self.renderer || !self.stage) return;
                self.animationFrame = requestAnimationFrame(loop);

                var p = self.container.position;
                var minX = self.renderer.width  - self.container.width;
                var minY = self.renderer.height - self.container.height;
                if (p.x < minX) p.x = minX;
                if (p.y < minY) p.y = minY;
                if (p.x > 0) p.x = 0;
                if (p.y > 0) p.y = 0;

                self.renderer.render(self.stage);
            })();
        },

        /* ── pan / drag ──────────────────────────────────────── */
        onDragStart: function (e) {
            var p = e.data.getLocalPosition(this.stage);
            this.dragStartX = p.x - this.container.position.x;
            this.dragStartY = p.y - this.container.position.y;
            this.isDragging = true;
            this.dragged = 0;
        },
        onDragMove: function (e) {
            if (!this.isDragging) return;
            var p = e.data.getLocalPosition(this.stage);
            var ox = this.container.position.x, oy = this.container.position.y;
            this.container.position.x = p.x - this.dragStartX;
            this.container.position.y = p.y - this.dragStartY;
            this.dragged += Math.abs(ox - this.container.position.x)
                          + Math.abs(oy - this.container.position.y);
        },
        onDragEnd: function () { this.isDragging = false; },

        /* ── resize ──────────────────────────────────────────── */
        onResize: function () {
            if (!this.renderer) return;
            this.renderer.resize(this.vpW(), this.vpH());
            this.container.scale.set(this.containerScale());
            this.alignContainer();
        },

        /* ── cleanup ─────────────────────────────────────────── */
        clearIntervals: function () {
            for (var i = 0; i < this.intervals.length; i++) clearInterval(this.intervals[i]);
            this.intervals = [];
        },

        teardown: function () {
            this._loading = false;
            this.clearIntervals();
            if (this.animationFrame) cancelAnimationFrame(this.animationFrame);
            if (this.renderer)  this.renderer.destroy();
            if (this.container) this.container.destroy(true);
            if (this.stage)     this.stage.destroy(true);
            if (this.loader)    this.loader.destroy();
            this.renderer = this.container = this.stage = this.loader = undefined;
            this._bgTexture = this._gridTexture = undefined;
            PIXI.utils.destroyTextureCache();
            window.removeEventListener('resize', this.onResize);
        }
    }
};
</script>
