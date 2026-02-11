<template>
    <div class="surface-viewport" ref="viewport"
         :class="{ 'is-landscape': isLandscape }">
        <canvas ref="canvas" class="surface"
                @pointerdown="onPointer"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="onPointerUp"></canvas>
        <div v-if="!ready" class="surface-loading">
            <div class="surface-spinner"></div>
        </div>
        <p v-if="errorMessage" class="surface-error-text">{{ errorMessage }}</p>
    </div>
</template>
<script>
import { EventBus } from '../event-bus';

/* ═══════════════════════════════════════════════════════════════
 *  SPRITE ATLAS DEFINITIONS  (from Sprites.js, as plain data)
 *
 *  We read the raw numbers only — no dependency on PIXI.Rectangle.
 *  Each entry: { x, y, w, h } = source region in sprite-grid.png
 * ═══════════════════════════════════════════════════════════════ */
var S = {
    plain: { x: 0, y: 0, w: 320, h: 200 },
    resources: {
        1: { x: 320, y: 0, w: 320, h: 200 },
        2: { x: 640, y: 0, w: 320, h: 200 },
        3: { x: 960, y: 0, w: 320, h: 200 },
        4: { x: 1280, y: 0, w: 320, h: 200 },
        5: { x: 1600, y: 0, w: 320, h: 200 },
        6: { x: 0, y: 200, w: 320, h: 200 },
        7: { x: 320, y: 200, w: 320, h: 200 }
    },
    buildings: {
        1:  { x: 640, y: 200, w: 320, h: 200 },
        2:  { 1: { x: 960, y: 200, w: 320, h: 200 }, 2: { x: 1280, y: 200, w: 320, h: 200 }, 3: { x: 1600, y: 200, w: 320, h: 200 }, 4: { x: 0, y: 400, w: 320, h: 200 }, 5: { x: 320, y: 400, w: 320, h: 200 }, 6: { x: 640, y: 400, w: 320, h: 200 }, 7: { x: 960, y: 400, w: 320, h: 200 } },
        3:  { x: 1280, y: 400, w: 320, h: 200 },
        4:  { x: 1600, y: 400, w: 320, h: 200 },
        5:  { x: 0, y: 600, w: 320, h: 200 },
        6:  { x: 320, y: 600, w: 320, h: 200 },
        7:  { x: 640, y: 600, w: 320, h: 200 },
        8:  { x: 960, y: 600, w: 320, h: 200 },
        9:  { x: 1280, y: 600, w: 320, h: 200 },
        10: { x: 1600, y: 600, w: 320, h: 200 }
    },
    constructions: {
        1: { x: 0, y: 800, w: 320, h: 200 }, 2: { x: 320, y: 800, w: 320, h: 200 },
        3: { x: 640, y: 800, w: 320, h: 200 }, 4: { x: 960, y: 800, w: 320, h: 200 },
        5: { x: 1280, y: 800, w: 320, h: 200 }, 6: { x: 1600, y: 800, w: 320, h: 200 },
        7: { x: 0, y: 1000, w: 320, h: 200 }, 8: { x: 320, y: 1000, w: 320, h: 200 },
        9: { x: 640, y: 1000, w: 320, h: 200 }, 10: { x: 960, y: 1000, w: 320, h: 200 }
    }
};

/* Tile constants */
var TW = 320, TH = 200;
var HALF_TW = TW / 2;   // 160
var HALF_TH = TH / 2;   // 100

/* Design size — matches the background images */
var DW = 1920, DH = 1080;

/* Isometric placement within design space (same as original code) */
function isoX(rx, ry) { return (rx - ry + 4) * 162 + (DW - 1608) / 2; }
function isoY(rx, ry) { return (rx + ry) * 81 + (DH - 888) / 2; }

/* Isometric diamond hit-test polygon (in tile-local coords) */
function pointInTile(px, py) {
    // Diamond: (0,120) → (160,40) → (320,120) → (160,200)
    // Half-space tests:
    var cx = px - 160, cy = py - 120;
    return Math.abs(cx) / 160 + Math.abs(cy) / 80 <= 1;
}

/* Resolve which atlas frame to draw for a grid slot */
function resolveFrame(grid, resourceId) {
    var f = S.plain;
    try {
        if (grid.construction) {
            f = S.constructions[grid.construction.building_id] || f;
        } else if (grid.type === 1) {
            if (grid.building_id) {
                var bs = S.buildings[grid.building_id];
                if (bs && bs.w) { f = bs; }
                else if (bs) { f = bs[resourceId] || bs[1] || f; }
            } else {
                f = S.resources[resourceId] || f;
            }
        } else if (grid.building_id) {
            var b = S.buildings[grid.building_id];
            if (b && b.w) { f = b; }
            else if (b) { f = b[resourceId] || b[1] || f; }
        }
    } catch (e) { f = S.plain; }
    if (!f || !f.w) f = S.plain;
    return f;
}

/* Load image → Promise */
function loadImg(url) {
    return new Promise(function (resolve, reject) {
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () { resolve(img); };
        img.onerror = function () { reject(new Error('Load failed: ' + url)); };
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

    data() {
        return {
            ready: false,
            errorMessage: '',
            isLandscape: false,
            planet: { resource_id: undefined, grids: [] },
            intervals: [],
            /* render state */
            _scale: 1,
            _offsetX: 0,
            _offsetY: 0,
            /* pan state */
            _panX: 0, _panY: 0,
            _dragStartX: 0, _dragStartY: 0,
            _dragging: false, _dragged: 0
        };
    },

    mounted() {
        this._destroyed = false;
        this._hasPlanetData = false;

        EventBus.$on('planet-updated', this.onPlanetData);
        EventBus.$emit('planet-data-request');

        this.$nextTick(() => {
            if (!this._destroyed && !this._hasPlanetData) this.fetchPlanet();
        });
        this._retryTimer = setTimeout(() => {
            if (!this._destroyed && !this._hasPlanetData) this.fetchPlanet();
        }, 3000);

        window.addEventListener('resize', this.onResize);
    },

    beforeDestroy() {
        this._destroyed = true;
        EventBus.$off('planet-updated', this.onPlanetData);
        clearTimeout(this._retryTimer);
        if (this._raf) cancelAnimationFrame(this._raf);
        window.removeEventListener('resize', this.onResize);
        this.clearIntervals();
    },

    methods: {
        toLocalPath(url) {
            if (!url) return url;
            try { return new URL(url, location.origin).pathname; }
            catch (e) { return url.replace(/^https?:\/\/[^\/]+/, ''); }
        },

        bgUrl() {
            return this.toLocalPath(
                this.backgroundTexture.replace('__resource__', this.planet.resource_id)
            );
        },

        atlasUrl() {
            return this.toLocalPath(this.gridTextureAtlas);
        },

        /* ── data fetching ───────────────────────────────────── */

        fetchPlanet() {
            if (this._fetchInFlight) return;
            this._fetchInFlight = true;
            axios.get('/api/planet').then(r => {
                this._fetchInFlight = false;
                if (!this._destroyed && r.data && r.data.id) this.onPlanetData(r.data);
            }).catch(() => {
                this._fetchInFlight = false;
                if (!this._destroyed && !this._hasPlanetData)
                    this.errorMessage = 'Planet data could not be loaded.';
            });
        },

        onPlanetData(planet) {
            if (this._destroyed || !planet || !planet.resource_id) return;
            this.planet = planet;

            if (this._hasPlanetData) {
                this.draw(); // refresh
                return;
            }
            this._hasPlanetData = true;
            this.initSurface();
        },

        /* ═══════════════════════════════════════════════════════
         *  INIT — load assets, size canvas, first draw
         * ═══════════════════════════════════════════════════════ */

        async initSurface() {
            this.errorMessage = '';
            try {
                var results = await Promise.all([
                    loadImg(this.bgUrl()),
                    loadImg(this.atlasUrl())
                ]);
                if (this._destroyed) return;

                this._bgImg    = results[0];
                this._atlasImg = results[1];

                this.sizeCanvas();
                this.draw();
                this.ready = true;
            } catch (err) {
                console.error('[Surface]', err);
                this.errorMessage = 'Surface could not be loaded.';
                this.ready = true;
            }
        },

        /* ═══════════════════════════════════════════════════════
         *  CANVAS SIZING  (responsive, orientation-aware)
         * ═══════════════════════════════════════════════════════ */

        sizeCanvas() {
            var vp = this.$refs.viewport;
            if (!vp) return;

            var cw = vp.clientWidth;
            var ch = vp.clientHeight;

            this.isLandscape = cw > ch;

            var scale, canvasW, canvasH, offX, offY;

            if (this.isLandscape) {
                // Contain: fit entire design inside viewport.
                scale = Math.min(cw / DW, ch / DH);
                canvasW = cw;
                canvasH = ch;
                offX = (cw - DW * scale) / 2;
                offY = (ch - DH * scale) / 2;
            } else {
                // Portrait: width-fill, height may exceed viewport (scroll).
                scale = cw / DW;
                canvasW = cw;
                canvasH = DH * scale;
                offX = 0;
                offY = 0;
            }

            var canvas = this.$refs.canvas;
            var dpr = window.devicePixelRatio || 1;

            // CSS size
            canvas.style.width  = canvasW + 'px';
            canvas.style.height = canvasH + 'px';

            // Backing store (crisp on HiDPI)
            canvas.width  = Math.round(canvasW * dpr);
            canvas.height = Math.round(canvasH * dpr);

            this._scale   = scale;
            this._offsetX = offX;
            this._offsetY = offY;
            this._dpr     = dpr;
            this._canvasW = canvasW;
            this._canvasH = canvasH;

            // Normalize grid coordinates.
            var grids = this.planet.grids;
            if (grids && grids.length) {
                var mx = grids[0].x, my = grids[0].y;
                for (var i = 1; i < grids.length; i++) {
                    if (grids[i].x < mx) mx = grids[i].x;
                    if (grids[i].y < my) my = grids[i].y;
                }
                this._gridMinX = mx;
                this._gridMinY = my;
            } else {
                this._gridMinX = 0;
                this._gridMinY = 0;
            }

            // Reset pan.
            this._panX = 0;
            this._panY = 0;
        },

        /* ═══════════════════════════════════════════════════════
         *  DRAW — pure Canvas 2D, no PixiJS
         * ═══════════════════════════════════════════════════════ */

        draw() {
            var canvas = this.$refs.canvas;
            if (!canvas || !this._bgImg) return;
            var ctx = canvas.getContext('2d');

            var dpr   = this._dpr;
            var scale = this._scale * dpr;
            var offX  = this._offsetX * dpr + this._panX * dpr;
            var offY  = this._offsetY * dpr + this._panY * dpr;

            // 1) Clear.
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#0b0e14';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            // 2) Background.
            ctx.drawImage(this._bgImg, offX, offY, DW * scale, DH * scale);

            // 3) Grid tiles.
            var grids = this.planet.grids;
            if (grids && grids.length && this._atlasImg) {
                for (var i = 0; i < grids.length; i++) {
                    this.drawSlot(ctx, grids[i], scale, offX, offY);
                }
            }

            // 4) Debug overlay (?debug=1).
            if (location.search.indexOf('debug=1') !== -1) {
                this.drawDebug(ctx, grids, scale, offX, offY);
            }
        },

        drawSlot(ctx, grid, scale, offX, offY) {
            var rx = grid.x - this._gridMinX;
            var ry = grid.y - this._gridMinY;
            var dx = isoX(rx, ry) * scale + offX;
            var dy = isoY(rx, ry) * scale + offY;
            var dw = TW * scale;
            var dh = TH * scale;

            var f = resolveFrame(grid, this.planet.resource_id);

            ctx.drawImage(
                this._atlasImg,
                f.x, f.y, f.w, f.h,   /* source rect */
                dx, dy, dw, dh         /* dest rect */
            );

            // Level number.
            if (grid.level) {
                ctx.fillStyle = '#fff';
                ctx.font = Math.round(14 * scale / this._dpr) + 'px "Exo 2", sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(grid.level, dx + dw / 2, dy + dh - 10 * scale / this._dpr);
            }
        },

        drawDebug(ctx, grids, scale, offX, offY) {
            if (!grids) return;
            ctx.strokeStyle = 'rgba(255,0,0,0.6)';
            ctx.lineWidth = 1;
            ctx.font = '10px monospace';
            ctx.fillStyle = 'red';
            ctx.textAlign = 'center';

            for (var i = 0; i < grids.length; i++) {
                var rx = grids[i].x - this._gridMinX;
                var ry = grids[i].y - this._gridMinY;
                var dx = isoX(rx, ry) * scale + offX;
                var dy = isoY(rx, ry) * scale + offY;
                var dw = TW * scale;
                var dh = TH * scale;
                ctx.strokeRect(dx, dy, dw, dh);
                ctx.fillText(rx + ',' + ry, dx + dw / 2, dy + dh / 2);
            }
        },

        /* ═══════════════════════════════════════════════════════
         *  HIT TESTING  (pointer → design coords → slot)
         * ═══════════════════════════════════════════════════════ */

        screenToDesign(clientX, clientY) {
            var canvas = this.$refs.canvas;
            var rect = canvas.getBoundingClientRect();
            var cssX = clientX - rect.left;
            var cssY = clientY - rect.top;
            var designX = (cssX - this._offsetX - this._panX) / this._scale;
            var designY = (cssY - this._offsetY - this._panY) / this._scale;
            return { x: designX, y: designY };
        },

        findSlotAt(designX, designY) {
            var grids = this.planet.grids;
            if (!grids) return null;

            for (var i = 0; i < grids.length; i++) {
                var rx = grids[i].x - this._gridMinX;
                var ry = grids[i].y - this._gridMinY;
                var slotX = isoX(rx, ry);
                var slotY = isoY(rx, ry);
                var localX = designX - slotX;
                var localY = designY - slotY;
                if (localX >= 0 && localX <= TW && localY >= 0 && localY <= TH) {
                    if (pointInTile(localX, localY)) {
                        return grids[i];
                    }
                }
            }
            return null;
        },

        /* ── pointer events (pan + tap) ──────────────────────── */

        onPointer(e) {
            this._dragging = true;
            this._dragged = 0;
            this._dragStartX = e.clientX - this._panX;
            this._dragStartY = e.clientY - this._panY;
        },

        onPointerMove(e) {
            if (!this._dragging) return;
            var nx = e.clientX - this._dragStartX;
            var ny = e.clientY - this._dragStartY;
            this._dragged += Math.abs(nx - this._panX) + Math.abs(ny - this._panY);
            this._panX = nx;
            this._panY = ny;
            this.draw();
        },

        onPointerUp(e) {
            if (!this._dragging) return;
            this._dragging = false;

            // If drag distance was small → treat as click/tap.
            if (this._dragged < 8) {
                var d = this.screenToDesign(e.clientX, e.clientY);
                var slot = this.findSlotAt(d.x, d.y);
                if (slot) {
                    EventBus.$emit(
                        slot.building_id ? 'building-click' : 'grid-click',
                        slot
                    );
                }
            }
        },

        /* ── resize ──────────────────────────────────────────── */

        onResize() {
            if (this._resizeTimer) return;
            this._resizeTimer = setTimeout(() => {
                this._resizeTimer = null;
                if (this._destroyed || !this._bgImg) return;
                this.sizeCanvas();
                this.draw();
            }, 100);
        },

        /* ── cleanup ─────────────────────────────────────────── */

        clearIntervals() {
            for (var i = 0; i < this.intervals.length; i++)
                clearInterval(this.intervals[i]);
            this.intervals = [];
        }
    }
};
</script>
