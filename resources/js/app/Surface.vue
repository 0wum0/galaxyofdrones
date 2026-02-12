<template>
    <div class="surface-viewport" ref="viewport">
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
import Filters from './Filters';

/* ── Sprite atlas (plain data, no PIXI) ─────────────────── */
var S = {
    plain:{x:0,y:0,w:320,h:200},
    resources:{
        1:{x:320,y:0,w:320,h:200},2:{x:640,y:0,w:320,h:200},
        3:{x:960,y:0,w:320,h:200},4:{x:1280,y:0,w:320,h:200},
        5:{x:1600,y:0,w:320,h:200},6:{x:0,y:200,w:320,h:200},
        7:{x:320,y:200,w:320,h:200}
    },
    buildings:{
        1:{x:640,y:200,w:320,h:200},
        2:{1:{x:960,y:200,w:320,h:200},2:{x:1280,y:200,w:320,h:200},3:{x:1600,y:200,w:320,h:200},4:{x:0,y:400,w:320,h:200},5:{x:320,y:400,w:320,h:200},6:{x:640,y:400,w:320,h:200},7:{x:960,y:400,w:320,h:200}},
        3:{x:1280,y:400,w:320,h:200},4:{x:1600,y:400,w:320,h:200},
        5:{x:0,y:600,w:320,h:200},6:{x:320,y:600,w:320,h:200},
        7:{x:640,y:600,w:320,h:200},8:{x:960,y:600,w:320,h:200},
        9:{x:1280,y:600,w:320,h:200},10:{x:1600,y:600,w:320,h:200}
    },
    constructions:{
        1:{x:0,y:800,w:320,h:200},2:{x:320,y:800,w:320,h:200},
        3:{x:640,y:800,w:320,h:200},4:{x:960,y:800,w:320,h:200},
        5:{x:1280,y:800,w:320,h:200},6:{x:1600,y:800,w:320,h:200},
        7:{x:0,y:1000,w:320,h:200},8:{x:320,y:1000,w:320,h:200},
        9:{x:640,y:1000,w:320,h:200},10:{x:960,y:1000,w:320,h:200}
    }
};

var TW = 320, TH = 200, DW = 1920, DH = 1080;

function isoX(rx, ry) { return (rx - ry + 4) * 162 + (DW - 1608) / 2; }
function isoY(rx, ry) { return (rx + ry) * 81 + (DH - 888) / 2; }

function pointInTile(px, py) {
    var cx = px - 160, cy = py - 120;
    return Math.abs(cx) / 160 + Math.abs(cy) / 80 <= 1;
}

/**
 * Resolve the overlay sprite for a grid slot.
 * Returns null for empty slots → only the faint base tile is drawn.
 *
 * A building sprite is returned ONLY when the slot has both:
 *   - building_id (which building type)
 *   - level > 0 (actually constructed, not just pre-placed)
 * This prevents the Generator's pre-placed but unbuilt slots
 * from showing finished building sprites.
 */
function resolveOverlay(grid, resourceId) {
    try {
        // Under construction → ghost/blueprint sprite.
        if (grid.construction) {
            return S.constructions[grid.construction.building_id] || null;
        }

        // Built building: must have building_id AND level > 0.
        if (grid.building_id && grid.level && grid.level > 0) {
            var b = S.buildings[grid.building_id];
            if (!b) return null;
            return b.w ? b : (b[resourceId] || b[1] || null);
        }

        // Resource slot (type 1) without building → crystal.
        if (grid.type === 1) {
            return S.resources[resourceId] || null;
        }
    } catch (e) {}
    return null;
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

/**
 * Compute transform for planet-in-viewport.
 *
 * @param {number} cw   Container width (CSS px)
 * @param {number} ch   Container height (CSS px)
 * @param {string} mode 'landscape' or 'portrait'
 * @returns {{ scale, tx, ty, pannable, minPanX, maxPanX, minPanY, maxPanY }}
 */
function computeTransform(cw, ch, mode) {
    var scale, tx, ty, pannable;

    // Both modes use COVER scaling (planet always fills viewport,
    // no starmap background visible). In landscape the planet is
    // close to fitting anyway; any minimal crop is acceptable.
    scale = Math.max(cw / DW, ch / DH);
    tx = (cw - DW * scale) / 2;
    ty = (ch - DH * scale) / 2;
    pannable = (mode === 'portrait');

    // Pan clamp bounds: the world image must ALWAYS cover the entire
    // viewport.  World left edge = tx + panX, must be <= 0.
    // World right edge = tx + panX + worldW, must be >= cw.
    var worldW = DW * scale;
    var worldH = DH * scale;

    // maxPanX: world left edge at 0 → panX = -tx
    // minPanX: world right edge at cw → panX = cw - worldW - tx
    var maxPanX = Math.max(0, -tx);
    var minPanX = Math.min(0, cw - worldW - tx);
    var maxPanY = Math.max(0, -ty);
    var minPanY = Math.min(0, ch - worldH - ty);

    // If world fits inside viewport (contain), disable pan.
    if (minPanX >= maxPanX) { minPanX = 0; maxPanX = 0; }
    if (minPanY >= maxPanY) { minPanY = 0; maxPanY = 0; }

    return {
        scale: scale, tx: tx, ty: ty,
        pannable: pannable,
        minPanX: minPanX, maxPanX: maxPanX,
        minPanY: minPanY, maxPanY: maxPanY
    };
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
            planet: { resource_id: undefined, grids: [] },
            _panX: 0, _panY: 0,
            _dragStartX: 0, _dragStartY: 0,
            _dragging: false, _dragged: 0
        };
    },

    mounted() {
        this._destroyed = false;
        this._hasPlanetData = false;
        this._tf = null; // current transform

        EventBus.$on('planet-updated', this.onPlanetData);
        EventBus.$emit('planet-data-request');

        this.$nextTick(() => {
            if (!this._destroyed && !this._hasPlanetData) this.fetchPlanet();
        });
        this._retryTimer = setTimeout(() => {
            if (!this._destroyed && !this._hasPlanetData) this.fetchPlanet();
        }, 3000);

        // ResizeObserver (primary) + window fallback.
        if (typeof ResizeObserver !== 'undefined' && this.$refs.viewport) {
            this._ro = new ResizeObserver(() => this.onContainerResize());
            this._ro.observe(this.$refs.viewport);
        }
        window.addEventListener('resize', this.onWindowResize);
        window.addEventListener('orientationchange', this.onOrientationChange);
    },

    beforeDestroy() {
        this._destroyed = true;
        EventBus.$off('planet-updated', this.onPlanetData);
        clearTimeout(this._retryTimer);
        if (this._timerInterval) clearInterval(this._timerInterval);
        if (this._syncInterval) clearInterval(this._syncInterval);
        if (this._ro) this._ro.disconnect();
        window.removeEventListener('resize', this.onWindowResize);
        window.removeEventListener('orientationchange', this.onOrientationChange);
    },

    methods: {
        toLocalPath(url) {
            if (!url) return url;
            try { return new URL(url, location.origin).pathname; }
            catch (e) { return url.replace(/^https?:\/\/[^\/]+/, ''); }
        },
        bgUrl() {
            return this.toLocalPath(this.backgroundTexture.replace('__resource__', this.planet.resource_id));
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
                if (!this._destroyed && !this._hasPlanetData) this.errorMessage = 'Planet data could not be loaded.';
            });
        },

        onPlanetData(planet) {
            if (this._destroyed || !planet || !planet.resource_id) return;
            this.planet = planet;
            this.normalizeGridCoords();
            if (this._hasPlanetData) { this.draw(); this.startTimers(); return; }
            this._hasPlanetData = true;
            this.initSurface();
        },

        normalizeGridCoords() {
            var g = this.planet.grids;
            if (!g || !g.length) { this._gmx = 0; this._gmy = 0; return; }
            var mx = g[0].x, my = g[0].y;
            for (var i = 1; i < g.length; i++) {
                if (g[i].x < mx) mx = g[i].x;
                if (g[i].y < my) my = g[i].y;
            }
            this._gmx = mx; this._gmy = my;
        },

        /* ── init ────────────────────────────────────────────── */
        async initSurface() {
            this.errorMessage = '';
            try {
                var imgs = await Promise.all([loadImg(this.bgUrl()), loadImg(this.atlasUrl())]);
                if (this._destroyed) return;
                this._bgImg = imgs[0];
                this._atlasImg = imgs[1];
                this.ready = true;
                // Wait for Vue to remove loading overlay, then measure + draw.
                this.$nextTick(() => { this.refit(); });
                this.startTimers();
            } catch (err) {
                this.errorMessage = 'Surface could not be loaded.';
                this.ready = true;
            }
        },

        /* ── sizing + transform ──────────────────────────────── */
        refit() {
            var vp = this.$refs.viewport;
            var canvas = this.$refs.canvas;
            if (!vp || !canvas) return;

            var cw = vp.clientWidth;
            var ch = vp.clientHeight;
            if (cw < 10 || ch < 10) return; // container not laid out yet

            var mode = cw >= ch ? 'landscape' : 'portrait';
            var dpr = Math.min(window.devicePixelRatio || 1, 2);

            canvas.style.width  = cw + 'px';
            canvas.style.height = ch + 'px';
            canvas.width  = Math.round(cw * dpr);
            canvas.height = Math.round(ch * dpr);

            this._tf = computeTransform(cw, ch, mode);
            this._tf.dpr = dpr;
            this._tf.cw = cw;
            this._tf.ch = ch;

            // Reset pan to centered.
            this._panX = 0;
            this._panY = 0;

            this.draw();
        },

        /* ── draw ────────────────────────────────────────────── */
        draw() {
            var canvas = this.$refs.canvas;
            if (!canvas || !this._bgImg || !this._tf) return;
            var ctx = canvas.getContext('2d');
            var tf = this._tf;
            var dpr = tf.dpr;

            // ── DPR-correct rendering ──────────────────────────
            // Reset transform, then scale by DPR once. All subsequent
            // draw calls use CSS-logical coordinates (not device pixels).
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

            // Smooth downscaling for detailed isometric art (not pixel art).
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';

            var cw = tf.cw, ch = tf.ch;
            var s = tf.scale;
            var ox = tf.tx + this._panX;
            var oy = tf.ty + this._panY;

            // 1) Fill dark (no starmap bleed).
            ctx.fillStyle = '#0b0e14';
            ctx.fillRect(0, 0, cw, ch);

            // 2) Background — rounded to integers for pixel-perfect.
            ctx.drawImage(this._bgImg,
                Math.round(ox), Math.round(oy),
                Math.round(DW * s), Math.round(DH * s));

            // 3) Grid slots.
            var grids = this.planet.grids;
            if (!grids || !grids.length || !this._atlasImg) return;
            for (var i = 0; i < grids.length; i++) {
                this.drawSlot(ctx, grids[i], s, ox, oy);
            }

            if (location.search.indexOf('debug=1') !== -1)
                this.drawDebug(ctx, grids, s, ox, oy);

            // Reset transform for any non-draw operations.
            ctx.setTransform(1, 0, 0, 1, 0, 0);
        },

        drawSlot(ctx, grid, s, ox, oy) {
            var rx = grid.x - this._gmx, ry = grid.y - this._gmy;

            // Integer positions — prevents anti-alias blur / cut-off.
            var dx = Math.round(isoX(rx, ry) * s + ox);
            var dy = Math.round(isoY(rx, ry) * s + oy);
            var dw = Math.round(TW * s);
            var dh = Math.round(TH * s);

            var overlay = resolveOverlay(grid, this.planet.resource_id);

            // Only draw sprites for slots that have actual content.
            // Empty slots: draw NOTHING (just invisible hit area).
            // This prevents the diamond-grid overlay that makes the
            // terrain look "cut up" / zerhäckselt.
            if (!overlay) return;

            // Base isometric tile (ground under the building/crystal).
            ctx.drawImage(this._atlasImg,
                S.plain.x, S.plain.y, S.plain.w, S.plain.h,
                dx, dy, dw, dh);

            // Building / resource / construction overlay.
            ctx.drawImage(this._atlasImg,
                overlay.x, overlay.y, overlay.w, overlay.h,
                dx, dy, dw, dh);

            // Level number (only for built buildings with level > 0).
            if (grid.building_id && grid.level && grid.level > 0) {
                var fs = Math.max(10, Math.round(14 * s));
                ctx.font = fs + 'px "Exo 2",sans-serif';
                ctx.textAlign = 'center';
                ctx.strokeStyle = '#0e141c'; ctx.lineWidth = 3; ctx.fillStyle = '#fff';
                var tx = dx + dw / 2;
                var ty = dy + dh - Math.round(8 * s);
                ctx.strokeText(grid.level, tx, ty);
                ctx.fillText(grid.level, tx, ty);
            }

            // Construction/upgrade/training timer.
            var rem = null, tc = '#fff';
            if (grid.construction) rem = grid.construction.remaining;
            else if (grid.upgrade) rem = grid.upgrade.remaining;
            else if (grid.training) { rem = grid.training.remaining; tc = '#ebb237'; }
            if (rem && rem > 0) {
                var tfs = Math.max(10, Math.round(14 * s));
                ctx.font = tfs + 'px "Exo 2",sans-serif';
                ctx.textAlign = 'center';
                ctx.strokeStyle = '#0e141c'; ctx.lineWidth = 3; ctx.fillStyle = tc;
                var label = Filters.timer(rem);
                ctx.strokeText(label, dx + dw / 2, dy + dh / 2 + Math.round(tfs / 3));
                ctx.fillText(label, dx + dw / 2, dy + dh / 2 + Math.round(tfs / 3));
            }
        },

        drawDebug(ctx, grids, s, ox, oy) {
            ctx.lineWidth = 1; ctx.font = '10px monospace'; ctx.textAlign = 'center';
            for (var i = 0; i < grids.length; i++) {
                var g = grids[i], rx = g.x - this._gmx, ry = g.y - this._gmy;
                var dx = Math.round(isoX(rx, ry) * s + ox);
                var dy = Math.round(isoY(rx, ry) * s + oy);
                var dw = Math.round(TW * s), dh = Math.round(TH * s);
                ctx.strokeStyle = g.building_id ? 'lime' : 'rgba(255,0,0,0.4)';
                ctx.strokeRect(dx, dy, dw, dh);
                ctx.fillStyle = g.building_id ? 'lime' : 'red';
                ctx.fillText(rx + ',' + ry + ' t' + g.type + ' b' + (g.building_id || '-') + ' L' + (g.level || 0), dx + dw / 2, dy + dh / 2);
            }
        },

        /* ── timers ──────────────────────────────────────────── */
        startTimers() {
            if (this._timerInterval) clearInterval(this._timerInterval);
            if (this._syncInterval) clearInterval(this._syncInterval);

            var grids = this.planet.grids;
            if (!grids) return;

            var hasTimers = false;
            for (var i = 0; i < grids.length; i++) {
                var g = grids[i];
                if ((g.construction && g.construction.remaining > 0) ||
                    (g.upgrade && g.upgrade.remaining > 0) ||
                    (g.training && g.training.remaining > 0)) { hasTimers = true; break; }
            }

            if (hasTimers) {
                // Client-side countdown (visual only, server is source of truth).
                this._timerInterval = setInterval(() => {
                    if (this._destroyed) { clearInterval(this._timerInterval); return; }
                    var any = false;
                    for (var j = 0; j < grids.length; j++) {
                        var g = grids[j];
                        if (g.construction && g.construction.remaining > 0) { g.construction.remaining--; any = true; }
                        if (g.upgrade && g.upgrade.remaining > 0) { g.upgrade.remaining--; any = true; }
                        if (g.training && g.training.remaining > 0) { g.training.remaining--; any = true; }
                    }
                    this.draw();
                    if (!any) {
                        clearInterval(this._timerInterval);
                        this._timerInterval = null;
                        this.fetchPlanet();
                    }
                }, 1000);
            }

            // Periodic server sync every 15 seconds. Catches:
            // - sync queue (instant build on server side)
            // - timer drift
            // - external state changes
            this._syncInterval = setInterval(() => {
                if (this._destroyed) { clearInterval(this._syncInterval); return; }
                this.fetchPlanet();
            }, 15000);
        },

        /* ── hit testing ─────────────────────────────────────── */
        screenToDesign(cx, cy) {
            var r = this.$refs.canvas.getBoundingClientRect();
            var tf = this._tf;
            return {
                x: (cx - r.left - tf.tx - this._panX) / tf.scale,
                y: (cy - r.top  - tf.ty - this._panY) / tf.scale
            };
        },

        findSlotAt(dx, dy) {
            var grids = this.planet.grids;
            if (!grids) return null;
            for (var i = grids.length - 1; i >= 0; i--) {
                var rx = grids[i].x - this._gmx, ry = grids[i].y - this._gmy;
                var lx = dx - isoX(rx, ry), ly = dy - isoY(rx, ry);
                if (lx >= 0 && lx <= TW && ly >= 0 && ly <= TH && pointInTile(lx, ly))
                    return grids[i];
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
            if (!this._dragging || !this._tf) return;
            if (this._tf.pannable) {
                var nx = e.clientX - this._dragStartX;
                var ny = e.clientY - this._dragStartY;
                this._dragged += Math.abs(nx - this._panX) + Math.abs(ny - this._panY);
                // Clamp pan to bounds.
                this._panX = Math.max(this._tf.minPanX, Math.min(this._tf.maxPanX, nx));
                this._panY = Math.max(this._tf.minPanY, Math.min(this._tf.maxPanY, ny));
                this.draw();
            } else {
                this._dragged += Math.abs(e.movementX || 0) + Math.abs(e.movementY || 0);
            }
        },

        onPointerUp(e) {
            if (!this._dragging) return;
            this._dragging = false;
            if (this._dragged < 10) {
                var d = this.screenToDesign(e.clientX, e.clientY);
                var slot = this.findSlotAt(d.x, d.y);
                if (slot) EventBus.$emit(slot.building_id ? 'building-click' : 'grid-click', slot);
            }
        },

        /* ── resize ──────────────────────────────────────────── */
        onContainerResize() {
            if (this._destroyed || !this._bgImg) return;
            this.refit();
        },

        onWindowResize() {
            if (this._resizeTimer) return;
            this._resizeTimer = setTimeout(() => {
                this._resizeTimer = null;
                if (this._destroyed || !this._bgImg) return;
                this.refit();
            }, 100);
        },

        onOrientationChange() {
            setTimeout(() => {
                if (!this._destroyed && this._bgImg) this.refit();
            }, 250);
        }
    }
};
</script>
