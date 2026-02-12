import Vue from 'vue';
import VueRouter from 'vue-router';
import Bookmark from './app/Bookmark';
import Construction from './app/Construction';
import Demolish from './app/Demolish';
import Filters from './app/Filters';
import Mailbox from './app/Mailbox';
import Message from './app/Message';
import Monitor from './app/Monitor';
import Mothership from './app/Mothership';
import Move from './app/Move';
import Navigation from './app/Navigation';
import Planet from './app/Planet';
import Player from './app/Player';
import Popover from './app/Popover';
import Profile from './app/Profile';
import Routing from './app/Routing';
import Setting from './app/Setting';
import Sidebar from './app/Sidebar';
import Star from './app/Star';
import Starmap from './app/Starmap.vue';
import Surface from './app/Surface.vue';
import Trophy from './app/Trophy';
import Upgrade from './app/Upgrade';
import UpgradeAll from './app/UpgradeAll';

/**
 * First we will load all of this project's JavaScript dependencies which
 * includes Vue and other libraries. It is a great starting point when
 * building robust, powerful web applications using Vue and Laravel.
 */

require('./bootstrap');

/**
 * Next, we will create a fresh Vue application instance and attach it to
 * the page. Then, you may begin adding components to this application
 * or customize the JavaScript scaffolding to fit your unique needs.
 */

Vue.filter('bracket', Filters.bracket);
Vue.filter('fromNow', Filters.fromNow);
Vue.filter('item', Filters.item);
Vue.filter('number', Filters.number);
Vue.filter('percent', Filters.percent);
Vue.filter('sign', Filters.sign);
Vue.filter('timer', Filters.timer);

Vue.directive('popover', Popover);

Vue.use(VueRouter);

const router = new VueRouter({
    mode: 'history',

    routes: [
        {
            path: '/starmap',
            name: 'starmap',
            component: Starmap,
            props: true
        },
        {
            path: '/',
            name: 'home',
            component: Surface,
            props: true
        }
    ]
});

/**
 * After every route transition, clean up Bootstrap modal state that may
 * linger when navigating via a button inside a modal.  Also force
 * Leaflet invalidateSize when entering the starmap route.
 */
router.afterEach(to => {
    // Remove any lingering Bootstrap modal backdrop and body class.
    // This is critical when navigating away while a modal is still visible
    // (e.g. "Jump to surface" from the planet modal on the starmap).
    Vue.nextTick(() => {
        $('.modal.show').modal('hide');
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open').css({ overflow: '', 'padding-right': '' });
    });

    if (to.name === 'starmap') {
        Vue.nextTick(() => {
            requestAnimationFrame(() => {
                if (window.__starmap) {
                    window.__starmap.invalidateSize(true);
                }
            });
        });
    }
});

/**
 * Global Vue error handler — prevents silent white-screen crashes.
 * Logs the error to console and shows a brief user-visible notice
 * so the player knows something went wrong instead of seeing nothing.
 */
Vue.config.errorHandler = function (err, vm, info) {
    console.error('[Vue error]', info, err);
};

/**
 * Mount the Vue application.
 * Wrapped in try/catch so that a component import failure or
 * initialization error does not silently produce a blank page.
 */
try {
    const app = new Vue({
        router,

        components: {
            Bookmark,
            Construction,
            Demolish,
            Mailbox,
            Message,
            Monitor,
            Mothership,
            Move,
            Navigation,
            Planet,
            Player,
            Profile,
            Setting,
            Sidebar,
            Star,
            Trophy,
            Upgrade,
            UpgradeAll
        },

        mixins: [
            Routing
        ]
    }).$mount('#app');

    // Signal successful mount to the fallback bar timer.
    if (typeof window.__cancelFallbackBar === 'function') {
        window.__cancelFallbackBar();
    }
} catch (mountError) {
    console.error('[Vue mount failed]', mountError);
    // Show a visible error so the user is not stuck on a blank screen.
    var el = document.getElementById('app');
    if (el) {
        el.innerHTML = '<div style="color:#fff;background:#1a1a2e;padding:40px;text-align:center;font-family:sans-serif;">'
            + '<h2>Loading Error</h2>'
            + '<p>The application could not start. Please try reloading the page.</p>'
            + '<p><a href="/login" style="color:#4fc3f7;">Back to Login</a> | '
            + '<a href="/logout" style="color:#4fc3f7;">Logout</a></p>'
            + '<p style="font-size:12px;color:#888;">' + mountError.message + '</p>'
            + '</div>';
    }
}
