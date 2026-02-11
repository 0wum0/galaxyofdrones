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
 *  SPRITE ATLAS DATA  (plain numbers, no PIXI dependency)
 * ═══════════════════════════════════════════════════════════════ */
var S = {
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
        2:  { 1:{x:960,y:200,w:320,h:200}, 2:{x:1280,y:200,w:320,h:200}, 3:{x:1600,y:200,w:320,h:200}, 4:{x:0,y:400,w:320,h:200}, 5:{x:320,y:400,w:320,h:200}, 6:{x:640,y:400,w:320,h:200}, 7:{x:960,y:400,w:320,h:200} },
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
        1:{x:0,y:800,w:320,h:200}, 2:{x:320,y:800,w:320,h:200},
        3:{x:640,y:800,w:320,h:200}, 4:{x:960,y:800,w:320,h:200},
        5:{x:1280,y:800,w:320,h:200}, 6:{x:1600,y:800,w:320,h:200},
        7:{x:0,y:1000,w:320,h:200}, 8:{x:320,y:1000,w:320,h:200},
        9:{x:640,y:1000,w:320,h:200}, 10:{x:960,y:1000,w:320,h:200}
    }
};

var TW = 320, TH = 200;
var DW = 1920, DH = 1080;

function isoX(rx, ry) { return (rx - ry + 4) * 162 + (DW - 1608) / 2; }
function isoY(rx, ry) { return (rx + ry) * 81 + (DH - 888) / 2; }

function pointInTile(px, py) {
    var cx = px - 160, cy = py - 120;
    return Math.abs(cx) / 160 + Math.abs(cy) / 80 <= 1;
}

/**
 * Resolve sprite frame ONLY for slots that have visible content.
 * Returns null for empty/unbuilt slots → caller skips rendering.
 */
function resolveFrame(grid, resourceId) {
    try {
        // 1) Under construction → construction ghost sprite.
        if (grid.construction) {
            return S.constructions[grid.construction.building_id] || null;
        }

        // 2) Has a building → building sprite.
        if (grid.building_id) {
            var b = S.buildings[grid.building_id];
            if (!b) return null;
            if (b.w) return b;                          // direct rectangle
            return b[resourceId] || b[1] || null;       // nested per-resource
        }

        // 3) Resource slot (type 1) WITHOUT building → resource crystal.
        if (grid.type === 1) {
            return S.resources[resourceId] || null;
        }

        // 4) Empty plain/central slot → render NOTHING.
        return null;
    } catch (e) {
        return null;
    }
}

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
            _scale: 1, _offsetX: 0, _offsetY: 0,
            _panX: 0, _panY: 0, _panLocked: false,
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
        window.addEventListener('orientationchange', this.onOrientationChange);
    },

    beforeDestroy() {
        this._destroyed = true;
        EventBus.$off('planet-updated', this.onPlanetData);
        clearTimeout(this._retryTimer);
        window.removeEventListener('resize', this.onResize);
        window.removeEventListener('orientationchange', this.onOrientationChange);
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

        atlasUrl() { return this.toLocalPath(this.gridTextureAtlas); },

        /* ── data ────────────────────────────────────────────── */

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
            if (this._hasPlanetData) { this.draw(); return; }
            this._hasPlanetData = true;
            this.initSurface();
        },

        /* ── init ────────────────────────────────────────────── */

        async initSurface() {
            this.errorMessage = '';
            try {
                var imgs = await Promise.all([loadImg(this.bgUrl()), loadImg(this.atlasUrl())]);
                if (this._destroyed) return;
                this._bgImg = imgs[0];
                this._atlasImg = imgs[1];
                this.computeLayout();
                this.draw();
                this.ready = true;
            } catch (err) {
                console.error('[Surface]', err);
                this.errorMessage = 'Surface could not be loaded.';
                this.ready = true;
            }
        },

        /* ═══════════════════════════════════════════════════════
         *  LAYOUT — responsive, orientation-aware
         * ═══════════════════════════════════════════════════════ */

        computeLayout() {
            var vp = this.$refs.viewport;
            var canvas = this.$refs.canvas;
            if (!vp || !canvas) return;

            // Real available area from the container element.
            var rect = vp.getBoundingClientRect();
            var cw = rect.width;
            var ch = rect.height;

            this.isLandscape = cw >= ch;

            var scale, offX, offY, canvasW, canvasH, panLocked;

            if (this.isLandscape) {
                // ── LANDSCAPE: cover (fill viewport, no black bars) ──
                scale = Math.max(cw / DW, ch / DH);
                canvasW = cw;
                canvasH = ch;
                offX = (cw - DW * scale) / 2;
                offY = (ch - DH * scale) / 2;
                panLocked = true;
            } else {
                // ── PORTRAIT: contain + pan ──
                scale = Math.min(cw / DW, ch / DH);
                canvasW = cw;
                canvasH = ch;
                offX = (cw - DW * scale) / 2;
                offY = (ch - DH * scale) / 2;
                panLocked = false;
            }

            var dpr = window.devicePixelRatio || 1;

            canvas.style.width  = canvasW + 'px';
            canvas.style.height = canvasH + 'px';
            canvas.width  = Math.round(canvasW * dpr);
            canvas.height = Math.round(canvasH * dpr);

            this._scale    = scale;
            this._offsetX  = offX;
            this._offsetY  = offY;
            this._dpr      = dpr;
            this._panLocked = panLocked;

            // Lock pan in landscape.
            if (panLocked) { this._panX = 0; this._panY = 0; }

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
        },

        /* ═══════════════════════════════════════════════════════
         *  DRAW — pure Canvas 2D
         * ═══════════════════════════════════════════════════════ */

        draw() {
            var canvas = this.$refs.canvas;
            if (!canvas || !this._bgImg) return;
            var ctx = canvas.getContext('2d');
            var dpr = this._dpr;
            var scale = this._scale * dpr;
            var offX = this._offsetX * dpr + this._panX * dpr;
            var offY = this._offsetY * dpr + this._panY * dpr;

            // 1) Clear.
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            // 2) Background (planet terrain).
            ctx.drawImage(this._bgImg, offX, offY, DW * scale, DH * scale);

            // 3) Grid sprites — ONLY occupied slots.
            var grids = this.planet.grids;
            if (grids && grids.length && this._atlasImg) {
                for (var i = 0; i < grids.length; i++) {
                    this.drawSlot(ctx, grids[i], scale, offX, offY);
                }
            }

            // 4) Debug (?debug=1).
            if (location.search.indexOf('debug=1') !== -1) {
                this.drawDebug(ctx, grids, scale, offX, offY);
            }
        },

        drawSlot(ctx, grid, scale, offX, offY) {
            var f = resolveFrame(grid, this.planet.resource_id);

            // Null = empty slot → don't draw anything.
            if (!f) return;

            var rx = grid.x - this._gridMinX;
            var ry = grid.y - this._gridMinY;
            var dx = isoX(rx, ry) * scale + offX;
            var dy = isoY(rx, ry) * scale + offY;
            var dw = TW * scale;
            var dh = TH * scale;

            ctx.drawImage(this._atlasImg, f.x, f.y, f.w, f.h, dx, dy, dw, dh);

            if (grid.level) {
                ctx.fillStyle = '#fff';
                ctx.strokeStyle = '#0e141c';
                ctx.lineWidth = 3;
                ctx.textAlign = 'center';
                ctx.font = Math.max(10, Math.round(14 * scale / this._dpr)) + 'px "Exo 2",sans-serif';
                var tx = dx + dw / 2;
                var ty = dy + dh - 8 * scale / this._dpr;
                ctx.strokeText(grid.level, tx, ty);
                ctx.fillText(grid.level, tx, ty);
            }
        },

        drawDebug(ctx, grids, scale, offX, offY) {
            if (!grids) return;
            ctx.lineWidth = 1;
            ctx.font = '10px monospace';
            ctx.textAlign = 'center';

            for (var i = 0; i < grids.length; i++) {
                var g = grids[i];
                var rx = g.x - this._gridMinX;
                var ry = g.y - this._gridMinY;
                var dx = isoX(rx, ry) * scale + offX;
                var dy = isoY(rx, ry) * scale + offY;
                var dw = TW * scale, dh = TH * scale;

                ctx.strokeStyle = g.building_id ? 'lime' : 'rgba(255,0,0,0.4)';
                ctx.strokeRect(dx, dy, dw, dh);
                ctx.fillStyle = g.building_id ? 'lime' : 'red';
                ctx.fillText(
                    rx + ',' + ry + ' t' + g.type + ' b' + (g.building_id || '-'),
                    dx + dw / 2, dy + dh / 2
                );
            }
        },

        /* ── hit testing ─────────────────────────────────────── */

        screenToDesign(cx, cy) {
            var r = this.$refs.canvas.getBoundingClientRect();
            return {
                x: (cx - r.left - this._offsetX - this._panX) / this._scale,
                y: (cy - r.top  - this._offsetY - this._panY) / this._scale
            };
        },

        findSlotAt(dx, dy) {
            var grids = this.planet.grids;
            if (!grids) return null;
            for (var i = grids.length - 1; i >= 0; i--) {
                var rx = grids[i].x - this._gridMinX;
                var ry = grids[i].y - this._gridMinY;
                var lx = dx - isoX(rx, ry);
                var ly = dy - isoY(rx, ry);
                if (lx >= 0 && lx <= TW && ly >= 0 && ly <= TH && pointInTile(lx, ly)) {
                    return grids[i];
                }
            }
            return null;
        },

        /* ── pointer events ──────────────────────────────────── */

        onPointer(e) {
            this._dragging = true;
            this._dragged = 0;
            this._dragStartX = e.clientX - this._panX;
            this._dragStartY = e.clientY - this._panY;
        },

        onPointerMove(e) {
            if (!this._dragging) return;
            // Pan only if NOT locked (portrait).
            if (!this._panLocked) {
                var nx = e.clientX - this._dragStartX;
                var ny = e.clientY - this._dragStartY;
                this._dragged += Math.abs(nx - this._panX) + Math.abs(ny - this._panY);
                this._panX = nx;
                this._panY = ny;
                this.draw();
            } else {
                // Track drag distance for tap detection even in landscape.
                this._dragged += Math.abs(e.movementX || 0) + Math.abs(e.movementY || 0);
            }
        },

        onPointerUp(e) {
            if (!this._dragging) return;
            this._dragging = false;
            if (this._dragged < 10) {
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

        /* ── resize / orientation ────────────────────────────── */

        onResize() {
            if (this._resizeTimer) return;
            this._resizeTimer = setTimeout(() => {
                this._resizeTimer = null;
                if (this._destroyed || !this._bgImg) return;
                this.computeLayout();
                this.draw();
            }, 80);
        },

        onOrientationChange() {
            // iOS/Android need a delayed re-measure after orientation settle.
            setTimeout(() => {
                if (!this._destroyed && this._bgImg) {
                    this.computeLayout();
                    this.draw();
                }
            }, 200);
        }
    }
};
</script>
