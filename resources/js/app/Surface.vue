<template>
    <div class="surface-viewport" ref="viewport">
        <canvas ref="canvas" class="surface"></canvas>
        <div v-if="!ready" class="surface-loading">
            <div class="surface-spinner"></div>
        </div>
        <p v-if="errorMessage" class="surface-error-text">
            {{ errorMessage }}
        </p>
    </div>
</template>
<script>
import {
    autoDetectRenderer, BaseTexture, Container, Sprite, utils, Text, Texture, Rectangle
} from 'pixi.js';

import { EventBus } from '../event-bus';
import Filters from './Filters';
import Sprites from './Sprites';

/**
 * Load an image and return a Promise that resolves with the Image element.
 * Crucially: does NOT set crossOrigin, so the browser loads it as a
 * plain same-origin request.  This is what makes it work where the PixiJS
 * Loader failed (it added crossOrigin='anonymous' causing CORS/tainted
 * texture issues).
 */
function loadImage(url) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = (e) => reject(new Error(`Image load failed: ${url}`));
        img.src = url;
    });
}

export default {
    props: {
        width: {
            type: Number,
            required: true
        },

        height: {
            type: Number,
            required: true
        },

        backgroundTexture: {
            type: String,
            required: true
        },

        gridTextureAtlas: {
            type: String,
            required: true
        }
    },

    data() {
        return {
            $viewport: undefined,
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
            bgBaseTexture: undefined,
            gridBaseTexture: undefined,
            ready: false,
            retryCount: 0,
            maxRetries: 3,
            errorMessage: '',
            breakpoints: [
                { minWidth: 1, maxHeight: 592, ratio: 0.64 },
                { minWidth: 992, maxHeight: 765, ratio: 0.827 },
                { minWidth: 1200, maxHeight: false, ratio: 1 }
            ],
            planet: {
                resource_id: undefined,
                grids: []
            },
            textStyle: {
                fontFamily: 'Exo 2',
                fontSize: '14px',
                fill: '#fff',
                align: 'center',
                stroke: '#0e141c',
                strokeThickness: 4
            }
        };
    },

    created() {
        utils.skipHello();
    },

    mounted() {
        this._destroyed = false;
        this._hasPlanetData = false;
        this.$viewport = $(this.$el);

        EventBus.$on('planet-updated', this.planetUpdated);

        // Strategy 1: Ask Sidebar for cached data.
        EventBus.$emit('planet-data-request');

        // Strategy 2: Immediate direct API fetch.
        this.$nextTick(() => {
            if (!this._destroyed && !this._hasPlanetData) {
                this.fetchPlanetDirect();
            }
        });

        // Strategy 3: Safety net after 3 s.
        this._retryTimer = setTimeout(() => {
            if (!this._destroyed && !this._hasPlanetData) {
                EventBus.$emit('planet-data-request');
            }
        }, 3000);
    },

    beforeDestroy() {
        this._destroyed = true;

        EventBus.$off('planet-updated', this.planetUpdated);

        if (this._retryTimer) {
            clearTimeout(this._retryTimer);
            this._retryTimer = undefined;
        }

        this.destroyPixi();
    },

    methods: {
        /**
         * Strip origin from URL → root-relative path.
         */
        toLocalPath(url) {
            if (!url) return url;
            try {
                return new URL(url, window.location.origin).pathname;
            } catch (e) {
                return url.replace(/^https?:\/\/[^/]+/, '');
            }
        },

        /**
         * CSS fallback: planet image on the viewport.
         */
        applyCssBackground() {
            if (!this.planet.resource_id || !this.$refs.viewport) return;
            this.$refs.viewport.style.backgroundImage =
                `url(${this.toLocalPath(this.background())})`;
        },

        // ─────────────── Data fetching ────────────────────────────
        fetchPlanetDirect() {
            if (this._fetchInFlight) return;
            this._fetchInFlight = true;

            axios.get('/api/planet').then(response => {
                this._fetchInFlight = false;
                if (!this._destroyed && response.data && response.data.id) {
                    this.planetUpdated(response.data);
                }
            }).catch(err => {
                this._fetchInFlight = false;
                console.error('[Surface] API fetch failed:', err);
                if (!this._destroyed && !this._hasPlanetData) {
                    this.errorMessage = 'Planet data could not be loaded.';
                }
            });
        },

        // ─────────────── Planet data handler ──────────────────────
        planetUpdated(planet) {
            if (this._destroyed) return;
            if (!planet || !planet.resource_id) return;

            if (this._hasPlanetData && this.stage) {
                this.planet = planet;
                this.applyCssBackground();
                this.safeSetup();
                return;
            }

            this._hasPlanetData = true;
            this.planet = planet;
            this.applyCssBackground();

            if (!this.stage && !this._loading) {
                this.$viewport = $(this.$el);
                this.initPixi();
            }
        },

        // ─────────────── PixiJS initialisation ────────────────────
        /**
         * Core init: load textures via native Image (NOT the PixiJS
         * Loader) then build the PixiJS scene.
         *
         * The PixiJS Loader (resource-loader) was the root cause of the
         * black-screen bug: it adds crossOrigin='anonymous' to Image
         * elements under certain URL conditions, which causes CORS
         * failures or "tainted canvas" in WebGL.  Native Image loading
         * without crossOrigin works reliably (proven by the modal
         * preview) and lets us create BaseTextures from the loaded
         * HTMLImageElement directly.
         */
        initPixi() {
            if (this._destroyed) return;
            this._loading = true;
            this.errorMessage = '';

            const bgUrl  = this.toLocalPath(this.background());
            const gridUrl = this.toLocalPath(this.gridTextureAtlas);

            // Load both images in parallel using plain <img> elements.
            Promise.all([loadImage(bgUrl), loadImage(gridUrl)])
                .then(([bgImg, gridImg]) => {
                    this._loading = false;
                    if (this._destroyed) return;

                    // Create PixiJS BaseTextures from the loaded images.
                    this.bgBaseTexture   = new BaseTexture(bgImg);
                    this.gridBaseTexture = new BaseTexture(gridImg);

                    if (this.bgBaseTexture.width < 1 || this.gridBaseTexture.width < 1) {
                        throw new Error('Texture dimensions are 0 — image may be tainted.');
                    }

                    this.createScene();
                    this.setup();
                    this.align();
                    this.animate();
                    this.ready = true;
                })
                .catch(err => {
                    this._loading = false;
                    console.error('[Surface] Texture load/init failed:', err);
                    if (!this._destroyed) {
                        this.destroyPixi();
                        this.retryInit();
                    }
                });
        },

        /**
         * Build the PixiJS stage, container and renderer.
         * Called once after textures are confirmed loaded.
         */
        createScene() {
            this.stage = new Container();

            this.container = new Container();
            this.container.interactive = true;
            this.container.interactiveChildren = true;
            this.container.scale.set(this.containerScale());

            // Mouse events
            this.container.on('mousedown', this.mouseDown);
            this.container.on('mousemove', this.mouseMove);
            this.container.on('mouseup', this.mouseUp);
            this.container.on('mouseupoutside', this.mouseUp);

            // Touch events (critical for mobile pan)
            this.container.on('touchstart', this.mouseDown);
            this.container.on('touchmove', this.mouseMove);
            this.container.on('touchend', this.mouseUp);
            this.container.on('touchendoutside', this.mouseUp);

            // Pointer events (unified — covers mouse + touch + pen)
            this.container.on('pointerdown', this.mouseDown);
            this.container.on('pointermove', this.mouseMove);
            this.container.on('pointerup', this.mouseUp);
            this.container.on('pointerupoutside', this.mouseUp);

            this.stage.addChild(this.container);

            this.renderer = autoDetectRenderer({
                width: this.rendererWidth(),
                height: this.rendererHeight(),
                view: this.$refs.canvas,
                transparent: true,
                // Required for interaction on some older WebGL contexts:
                antialias: false,
                resolution: 1
            });

            window.addEventListener('resize', this.resize);
        },

        retryInit() {
            this.retryCount += 1;

            if (this.retryCount > this.maxRetries) {
                console.error(`[Surface] Max retries (${this.maxRetries}) exhausted.`);
                this.ready = true;
                return;
            }

            const delay = Math.min(1000 * (2 ** (this.retryCount - 1)), 8000);

            setTimeout(() => {
                if (!this._destroyed && !this.stage && !this._loading && this.planet.resource_id) {
                    this.initPixi();
                }
            }, delay);
        },

        destroyPixi() {
            this._loading = false;
            this.clearIntervals();

            if (this.animationFrame) { cancelAnimationFrame(this.animationFrame); this.animationFrame = undefined; }
            if (this.renderer) { this.renderer.destroy(); this.renderer = undefined; }
            if (this.container) { this.container.destroy(true); this.container = undefined; }
            if (this.stage) { this.stage.destroy(true); this.stage = undefined; }
            if (this.bgBaseTexture) { this.bgBaseTexture.destroy(); this.bgBaseTexture = undefined; }
            if (this.gridBaseTexture) { this.gridBaseTexture.destroy(); this.gridBaseTexture = undefined; }

            utils.destroyTextureCache();
            window.removeEventListener('resize', this.resize);
        },

        // ─────────────── Scene setup ──────────────────────────────
        setup() {
            this.clearIntervals();
            this.container.removeChildren();

            // 1) Background sprite (layer 0 — behind everything)
            const bgTexture = new Texture(this.bgBaseTexture);
            this.container.addChild(new Sprite(bgTexture));

            // 2) Grid sprites (layer 1+ — on top of background)
            if (this.planet.grids && this.planet.grids.length) {
                _.forEach(this.planet.grids, grid => {
                    this.container.addChild(this.gridSprite(grid));
                });
            }
        },

        safeSetup() {
            if (!this.stage || !this.bgBaseTexture || !this.gridBaseTexture) return;
            try { this.setup(); }
            catch (err) { console.error('[Surface] setup error:', err); }
        },

        clearIntervals() {
            _.forEach(this.intervals, interval => clearInterval(interval));
            this.intervals = [];
        },

        // ─────────────── Layout ───────────────────────────────────
        resize() {
            if (!this.renderer) return;
            this.renderer.resize(this.rendererWidth(), this.rendererHeight());
            this.container.scale.set(this.containerScale());
            this.align();
        },

        align() {
            this.container.position.x = this.centerX();
            this.container.position.y = this.centerY();
        },

        // ─────────────── Render loop ──────────────────────────────
        animate() {
            if (this._destroyed || !this.renderer || !this.stage || !this.container) return;

            this.animationFrame = requestAnimationFrame(this.animate);

            // Clamp container position so the user can't pan beyond the edges.
            const minX = this.containerX();
            const minY = this.containerY();

            if (this.container.position.x < minX) this.container.position.x = minX;
            if (this.container.position.y < minY) this.container.position.y = minY;
            if (this.container.position.x > 0) this.container.position.x = 0;
            if (this.container.position.y > 0) this.container.position.y = 0;

            this.renderer.render(this.stage);
        },

        // ─────────────── Pan / drag (mouse + touch) ──────────────
        mouseDown(e) {
            const start = e.data.getLocalPosition(this.container.parent);
            this.dragStartX = start.x - this.container.position.x;
            this.dragStartY = start.y - this.container.position.y;
            this.isDragging = true;
            this.dragged = 0;
        },

        mouseMove(e) {
            if (!this.isDragging) return;
            const moved = e.data.getLocalPosition(this.container.parent);
            const prevX = this.container.position.x;
            const prevY = this.container.position.y;
            this.container.position.x = moved.x - this.dragStartX;
            this.container.position.y = moved.y - this.dragStartY;
            this.dragged += Math.abs(prevX - this.container.position.x);
            this.dragged += Math.abs(prevY - this.container.position.y);
        },

        mouseUp() {
            this.isDragging = false;
        },

        // ─────────────── Texture helpers ──────────────────────────
        background() {
            return this.backgroundTexture.replace('__resource__', this.planet.resource_id);
        },

        // ─────────────── Layout helpers ───────────────────────────
        rendererWidth() {
            const w = this.$viewport ? this.$viewport.width() : 0;
            return w > 1 ? w : this.width;
        },

        rendererHeight() {
            const h = this.$viewport ? this.$viewport.height() : 0;
            return h > 1 ? h : this.height;
        },

        centerX() { return this.containerX() / 2; },
        centerY() { return this.containerY() / 2; },
        containerX() { return this.renderer.width - this.container.width; },
        containerY() { return this.renderer.height - this.container.height; },

        containerScale() {
            const width = this.rendererWidth();
            const height = this.rendererHeight();

            let current = _.findLast(this.breakpoints, bp => bp.minWidth <= width);
            if (!current) return 1;
            if (current.maxHeight === false || current.maxHeight >= height) return current.ratio;

            current = _.findLast(this.breakpoints, bp => bp.maxHeight >= height);
            return !_.isUndefined(current) ? current.ratio : 1;
        },

        // ─────────────── Grid sprites ─────────────────────────────
        gridSprite(grid) {
            const sprite = new Sprite(this.gridTexture(grid));
            sprite.interactive = true;
            sprite.hitArea = Sprites.hitArea;
            sprite.x = this.gridX(grid);
            sprite.y = this.gridY(grid);
            sprite.on('mouseover', () => { sprite.alpha = 0.6; });
            sprite.on('mouseout',  () => { sprite.alpha = 1; });
            sprite.on('click',     () => this.gridClick(grid));
            sprite.on('tap',       () => this.gridClick(grid));
            this.gridLevel(grid, sprite);
            this.gridRemaining(grid, sprite);
            return sprite;
        },

        gridTexture(grid) {
            let frame = Sprites.plain;
            try {
                if (grid.construction) {
                    frame = Sprites.constructions[grid.construction.building_id] || Sprites.plain;
                } else if (grid.type === 1) {
                    if (grid.building_id) {
                        const bs = Sprites.buildings[grid.building_id];
                        if (bs && typeof bs === 'object' && !bs.width) {
                            frame = bs[this.planet.resource_id] || Sprites.plain;
                        } else {
                            frame = bs || Sprites.plain;
                        }
                    } else {
                        frame = Sprites.resources[this.planet.resource_id] || Sprites.plain;
                    }
                } else if (grid.building_id) {
                    frame = Sprites.buildings[grid.building_id] || Sprites.plain;
                }
            } catch (e) {
                frame = Sprites.plain;
            }
            return new Texture(this.gridBaseTexture, frame);
        },

        gridX(grid) { return (grid.x - grid.y + 4) * 162 + (this.width - 1608) / 2; },
        gridY(grid) { return (grid.x + grid.y) * 81 + (this.height - 888) / 2; },

        gridClick(grid) {
            if (this.dragged > this.clickTreshold) return;
            EventBus.$emit(grid.building_id ? 'building-click' : 'grid-click', grid);
        },

        gridLevel(grid, sprite) {
            if (!grid.level) return;
            const text = new Text(grid.level, this.textStyle);
            text.position.x = (sprite.width - text.width) / 2;
            text.position.y = sprite.height - 50;
            sprite.addChild(text);
        },

        gridRemaining(grid, sprite) {
            let remaining, textStyle;
            if (grid.construction)  { ({ remaining } = grid.construction); ({ textStyle } = this); }
            else if (grid.training) { ({ remaining } = grid.training); textStyle = _.assignIn({}, this.textStyle, { fill: '#ebb237' }); }
            else if (grid.upgrade)  { ({ remaining } = grid.upgrade); ({ textStyle } = this); }
            if (!remaining) return;

            const text = new Text(Filters.timer(remaining), textStyle);
            text.position.x = (sprite.width - text.width) / 2;
            text.position.y = (sprite.height - text.height) / 2;
            sprite.addChild(text);

            const interval = setInterval(() => {
                remaining -= 1;
                text.text = Filters.timer(remaining);
                if (!remaining) clearInterval(interval);
            }, 1000);
            this.intervals.push(interval);
        }
    }
};
</script>
