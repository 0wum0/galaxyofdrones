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

/**
 * Load an image via native <img> (no CORS, no PixiJS Loader).
 */
function loadImg(url) {
    return new Promise(function (resolve, reject) {
        var img = new Image();
        img.onload = function () { resolve(img); };
        img.onerror = function () { reject(new Error('Image load failed: ' + url)); };
        img.src = url;
    });
}

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
            bgBaseTex: null,
            gridBaseTex: null,
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

        // 1) Ask Sidebar for cached data (instant if available).
        EventBus.$emit('planet-data-request');

        // 2) Direct API fetch — fires immediately if Sidebar had nothing.
        this.$nextTick(function () {
            if (!this._destroyed && !this._hasPlanetData) {
                this.fetchPlanet();
            }
        }.bind(this));

        // 3) Safety-net retry.
        this._timer = setTimeout(function () {
            if (!this._destroyed && !this._hasPlanetData) {
                this.fetchPlanet();
            }
        }.bind(this), 3000);
    },

    beforeDestroy: function () {
        this._destroyed = true;
        EventBus.$off('planet-updated', this.onPlanetData);
        clearTimeout(this._timer);
        this.teardown();
    },

    methods: {
        /* ── helpers ─────────────────────────────────────────── */
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

        gridUrl: function () {
            return this.localUrl(this.gridTextureAtlas);
        },

        vpWidth: function () {
            var w = this.$refs.viewport ? this.$refs.viewport.clientWidth : 0;
            return w > 1 ? w : this.width;
        },

        vpHeight: function () {
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
                if (!self._destroyed && r.data && r.data.id) {
                    self.onPlanetData(r.data);
                }
            }).catch(function (err) {
                self._fetchInFlight = false;
                if (!self._destroyed && !self._hasPlanetData) {
                    self.errorMessage = 'Planet data could not be loaded.';
                }
            });
        },

        onPlanetData: function (planet) {
            if (this._destroyed || !planet || !planet.resource_id) return;

            // Refresh existing scene.
            if (this._hasPlanetData && this.stage) {
                this.planet = planet;
                try { this.buildScene(); } catch (e) { /* keep old scene */ }
                return;
            }

            this._hasPlanetData = true;
            this.planet = planet;

            // Show CSS fallback immediately.
            if (this.$refs.viewport) {
                this.$refs.viewport.style.backgroundImage = 'url(' + this.bgUrl() + ')';
            }

            if (!this._loading && !this.stage) {
                this.init();
            }
        },

        /* ── PixiJS init ─────────────────────────────────────── */
        init: function () {
            if (this._destroyed || this._loading) return;
            this._loading = true;
            this.errorMessage = '';

            var self = this;
            var bg  = this.bgUrl();
            var grid = this.gridUrl();

            Promise.all([loadImg(bg), loadImg(grid)]).then(function (imgs) {
                self._loading = false;
                if (self._destroyed) return;

                try {
                    // Create BaseTextures from loaded HTMLImageElements.
                    self.bgBaseTex  = new PIXI.BaseTexture(imgs[0]);
                    self.gridBaseTex = new PIXI.BaseTexture(imgs[1]);

                    self.createRenderer();
                    self.buildScene();
                    self.startLoop();
                    self.ready = true;
                } catch (err) {
                    console.error('[Surface] init error:', err);
                    self.errorMessage = 'Renderer error: ' + err.message;
                    self.ready = true; // show CSS fallback
                }
            }).catch(function (err) {
                self._loading = false;
                console.error('[Surface] image load error:', err);
                // Even if textures fail → build scene with Graphics fallback.
                try {
                    self.bgBaseTex = null;
                    self.gridBaseTex = null;
                    self.createRenderer();
                    self.buildScene();
                    self.startLoop();
                    self.ready = true;
                } catch (e2) {
                    console.error('[Surface] fallback init error:', e2);
                    self.errorMessage = 'Surface could not be loaded.';
                    self.ready = true;
                }
            });
        },

        createRenderer: function () {
            this.stage = new PIXI.Container();
            this.container = new PIXI.Container();
            this.container.interactive = true;
            this.container.interactiveChildren = true;

            // Pan / drag — mouse + touch + pointer
            var events = {
                mousedown: this.onDragStart, mousemove: this.onDragMove,
                mouseup: this.onDragEnd, mouseupoutside: this.onDragEnd,
                touchstart: this.onDragStart, touchmove: this.onDragMove,
                touchend: this.onDragEnd, touchendoutside: this.onDragEnd,
                pointerdown: this.onDragStart, pointermove: this.onDragMove,
                pointerup: this.onDragEnd, pointerupoutside: this.onDragEnd
            };
            for (var ev in events) {
                this.container.on(ev, events[ev]);
            }

            this.stage.addChild(this.container);

            this.renderer = PIXI.autoDetectRenderer({
                width: this.vpWidth(),
                height: this.vpHeight(),
                view: this.$refs.canvas,
                backgroundColor: 0x0b0e14,
                antialias: false,
                resolution: 1
            });

            window.addEventListener('resize', this.onResize);
        },

        /* ── scene building ──────────────────────────────────── */
        buildScene: function () {
            this.clearIntervals();
            this.container.removeChildren();

            var scale = this.containerScale();
            this.container.scale.set(scale);

            // 1) Background — texture sprite or filled rectangle.
            if (this.bgBaseTex && this.bgBaseTex.width > 0) {
                var bgSprite = new PIXI.Sprite(new PIXI.Texture(this.bgBaseTex));
                this.container.addChild(bgSprite);
            } else {
                // Fallback: dark filled rectangle so grids are visible.
                var bg = new PIXI.Graphics();
                bg.beginFill(0x0b0e14);
                bg.drawRect(0, 0, this.width, this.height);
                bg.endFill();
                this.container.addChild(bg);
            }

            // 2) Grid tiles.
            var grids = this.planet.grids;
            if (grids && grids.length) {
                for (var i = 0; i < grids.length; i++) {
                    try {
                        this.container.addChild(this.makeGridSlot(grids[i]));
                    } catch (e) {
                        // Single grid slot failure must not kill the loop.
                        console.warn('[Surface] grid slot error:', e.message);
                    }
                }
            }

            // Centre the container.
            this.alignContainer();
        },

        makeGridSlot: function (grid) {
            var slot;
            var x = this.gridX(grid);
            var y = this.gridY(grid);

            if (this.gridBaseTex && this.gridBaseTex.width > 0) {
                // Sprite from the grid atlas.
                var frame = this.pickFrame(grid);
                var tex = new PIXI.Texture(this.gridBaseTex, frame);
                slot = new PIXI.Sprite(tex);
            } else {
                // Fallback: outlined diamond shape so the grid is visible
                // and clickable even without textures.
                slot = new PIXI.Graphics();
                slot.lineStyle(1, 0x4183d7, 0.8);
                slot.beginFill(0x19222f, 0.5);
                slot.moveTo(160, 0);
                slot.lineTo(320, 80);
                slot.lineTo(160, 160);
                slot.lineTo(0, 80);
                slot.closePath();
                slot.endFill();
            }

            slot.x = x;
            slot.y = y;
            slot.interactive = true;
            slot.buttonMode = true;
            slot.hitArea = Sprites.hitArea;

            var self = this;
            slot.on('pointerdown', function () { self._slotPointerDown = true; });
            slot.on('pointerup', function () {
                if (self._slotPointerDown && self.dragged <= self.clickTreshold) {
                    self.gridClick(grid);
                }
                self._slotPointerDown = false;
            });
            slot.on('pointerover', function () { slot.alpha = 0.65; });
            slot.on('pointerout',  function () { slot.alpha = 1; });

            // Level label.
            if (grid.level) {
                var lvl = new PIXI.Text(grid.level, this.textStyle);
                lvl.x = 160 - lvl.width / 2;
                lvl.y = (slot.height || 160) - 50;
                slot.addChild(lvl);
            }

            // Timer label.
            this.addTimer(grid, slot);

            return slot;
        },

        pickFrame: function (grid) {
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
            } catch (e) { /* keep default frame */ }
            return frame;
        },

        addTimer: function (grid, slot) {
            var remaining, style;
            if (grid.construction)       { remaining = grid.construction.remaining; style = this.textStyle; }
            else if (grid.training)      { remaining = grid.training.remaining; style = _.assignIn({}, this.textStyle, { fill: '#ebb237' }); }
            else if (grid.upgrade)       { remaining = grid.upgrade.remaining; style = this.textStyle; }
            if (!remaining) return;

            var text = new PIXI.Text(Filters.timer(remaining), style);
            text.x = 160 - text.width / 2;
            text.y = ((slot.height || 160) - text.height) / 2;
            slot.addChild(text);

            var interval = setInterval(function () {
                remaining -= 1;
                text.text = Filters.timer(remaining);
                if (!remaining) clearInterval(interval);
            }, 1000);
            this.intervals.push(interval);
        },

        /* ── grid events ─────────────────────────────────────── */
        gridClick: function (grid) {
            EventBus.$emit(grid.building_id ? 'building-click' : 'grid-click', grid);
        },

        /* ── layout ──────────────────────────────────────────── */
        gridX: function (grid) { return (grid.x - grid.y + 4) * 162 + (this.width - 1608) / 2; },
        gridY: function (grid) { return (grid.x + grid.y) * 81 + (this.height - 888) / 2; },

        containerScale: function () {
            var w = this.vpWidth(), h = this.vpHeight();
            if (w >= 1200) return 1;
            if (w >= 992 && h >= 765) return 0.827;
            if (h >= 592) return 0.64;
            return 0.64;
        },

        alignContainer: function () {
            if (!this.renderer || !this.container) return;
            var cx = (this.renderer.width - this.container.width) / 2;
            var cy = (this.renderer.height - this.container.height) / 2;
            this.container.position.set(cx, cy);
        },

        /* ── render loop ─────────────────────────────────────── */
        startLoop: function () {
            var self = this;
            function loop() {
                if (self._destroyed || !self.renderer || !self.stage) return;
                self.animationFrame = requestAnimationFrame(loop);

                // Clamp pan.
                var minX = self.renderer.width - self.container.width;
                var minY = self.renderer.height - self.container.height;
                var pos = self.container.position;
                if (pos.x < minX) pos.x = minX;
                if (pos.y < minY) pos.y = minY;
                if (pos.x > 0) pos.x = 0;
                if (pos.y > 0) pos.y = 0;

                self.renderer.render(self.stage);
            }
            loop();
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
            var px = this.container.position.x;
            var py = this.container.position.y;
            this.container.position.x = p.x - this.dragStartX;
            this.container.position.y = p.y - this.dragStartY;
            this.dragged += Math.abs(px - this.container.position.x) + Math.abs(py - this.container.position.y);
        },

        onDragEnd: function () { this.isDragging = false; },

        /* ── resize ──────────────────────────────────────────── */
        onResize: function () {
            if (!this.renderer) return;
            this.renderer.resize(this.vpWidth(), this.vpHeight());
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
            if (this.renderer)   this.renderer.destroy();
            if (this.container)  this.container.destroy(true);
            if (this.stage)      this.stage.destroy(true);
            if (this.bgBaseTex)  this.bgBaseTex.destroy();
            if (this.gridBaseTex) this.gridBaseTex.destroy();
            this.renderer = this.container = this.stage = this.bgBaseTex = this.gridBaseTex = undefined;
            PIXI.utils.destroyTextureCache();
            window.removeEventListener('resize', this.onResize);
        }
    }
};
</script>
