import Moment from 'moment';

export default {
    bracket(value) {
        return `(${value})`;
    },

    fromNow(value) {
        const datetime = Moment(value, 'YYYY-MM-DD HH:mm:ss');

        if (!datetime.isValid()) {
            return value;
        }

        return datetime.fromNow();
    },

    item(value, type = 'resource') {
        // Extract the ID from value (object with .id, or raw number/string).
        var id = (value && _.has(value, 'id')) ? value.id : value;

        // Guard: if id is falsy (undefined, null, 0, '') or not a positive
        // integer, return an empty string so no invalid CSS class is applied.
        // This prevents showing the default sprite-sheet position (0,0) which
        // is resource-1 (crystal) — the source of "wrong image in modal" bugs.
        if (!id || (typeof id !== 'number' && typeof id !== 'string')) {
            return '';
        }

        return type + '-' + id;
    },

    number(value, decimals = 2) {
        const abs = Math.abs(value);

        if (abs >= 10 ** 13) {
            return `${(value / (10 ** 12)).toFixed(decimals)}t`;
        }

        if (abs >= 10 ** 10) {
            return `${(value / (10 ** 9)).toFixed(decimals)}b`;
        }

        if (abs >= 10 ** 7) {
            return `${(value / (10 ** 6)).toFixed(decimals)}m`;
        }

        if (abs >= 10 ** 4) {
            return `${(value / (10 ** 3)).toFixed(decimals)}k`;
        }

        return Math.round(value);
    },

    percent(value) {
        return `${(value * 100).toFixed(0)}%`;
    },

    sign(value, number) {
        let direction = number;

        if (!direction) {
            direction = value;
        }

        const result = _.isNumber(value)
            ? Math.abs(value)
            : value;

        if (direction < 0) {
            return `-${result}`;
        }

        return `+${result}`;
    },

    timer(value) {
        const abs = Math.abs(value);

        let segments = [
            abs / 3600,
            (abs % 3600) / 60,
            abs % 60
        ];

        segments = _.map(
            segments, segment => _.padStart(Math.floor(segment), 2, '0')
        );

        const [
            hours, minutes, seconds
        ] = segments;

        return `${hours}:${minutes}:${seconds}`;
    }
};
