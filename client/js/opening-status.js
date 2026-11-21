/**
 * Keeps "Open · closes at 18:00" style status badges correct on cached pages.
 *
 * Each [data-store-status] element carries the next days' resolved opening hours (exceptions already applied by
 * the server) plus pre-translated labels in data-hours. This script recomputes the status for the current moment
 * in the location's own timezone, and again every minute. No network requests, no cookies.
 */
(function () {
  'use strict';

  function localParts(tz) {
    var parts = {};
    new Intl.DateTimeFormat('en-CA', {
      timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
    }).formatToParts(new Date()).forEach(function (p) { parts[p.type] = p.value; });
    return { date: parts.year + '-' + parts.month + '-' + parts.day, minutes: (+parts.hour) * 60 + (+parts.minute) };
  }

  function toMinutes(t) {
    var bits = t.split(':');
    return (+bits[0]) * 60 + (+bits[1]);
  }

  function formatTime(t, h12) {
    if (!h12) { return t; }
    var m = toMinutes(t) % 1440;
    var h = Math.floor(m / 60);
    var suffix = h < 12 ? 'AM' : 'PM';
    h = h % 12 || 12;
    return h + ':' + ('0' + (m % 60)).slice(-2) + ' ' + suffix;
  }

  function fill(template, values) {
    return template.replace(/\{(\w+)\}/g, function (all, key) { return values[key] !== undefined ? values[key] : all; });
  }

  // Absolute minute intervals relative to the first day's midnight; overnight ranges spill into the next day.
  function intervals(days) {
    var list = [];
    days.forEach(function (day, index) {
      day.r.forEach(function (range) {
        var start = index * 1440 + toMinutes(range[0]);
        var end = index * 1440 + toMinutes(range[1]);
        if (end <= start) { end += 1440; }
        list.push({ start: start, end: end, day: index });
      });
    });
    // Merge touching ranges (e.g. 00:00-24:00 on consecutive days).
    list.sort(function (a, b) { return a.start - b.start; });
    var merged = [];
    list.forEach(function (iv) {
      var last = merged[merged.length - 1];
      if (last && iv.start <= last.end) { last.end = Math.max(last.end, iv.end); } else { merged.push(iv); }
    });
    return merged;
  }

  function weekdayOf(dateString) {
    var d = new Date(dateString + 'T12:00:00Z').getUTCDay();
    return d === 0 ? 7 : d;
  }

  function statusText(data) {
    if (data.status !== 'Open' || !data.days.length) { return null; }
    var now = localParts(data.tz);
    var offset = data.days.findIndex(function (d) { return d.d === now.date; });
    if (offset < 0) { return null; }
    var at = offset * 1440 + now.minutes;
    var list = intervals(data.days);
    var labels = data.labels;
    var current = list.find(function (iv) { return iv.start <= at && at < iv.end; });

    if (current) {
      var endDay = Math.floor(current.end / 1440);
      var endMin = current.end % 1440;
      if (endDay >= data.days.length) { return { text: labels.always, cls: 'is-open' }; }
      var sameDay = endDay === offset || (endDay === offset + 1 && endMin === 0);
      var time = formatTime(endMin === 0 && sameDay ? '24:00' : ('0' + Math.floor(endMin / 60)).slice(-2) + ':' + ('0' + endMin % 60).slice(-2), data.h12);
      if (current.end - at <= data.soon[0]) { return { text: fill(labels.closesSoon, { time: time }), cls: 'is-open is-closing-soon' }; }
      if (sameDay) { return { text: fill(labels.open, { time: time }), cls: 'is-open' }; }
      return { text: fill(labels.openDay, { day: data.weekdays[weekdayOf(data.days[endDay].d)], time: time }), cls: 'is-open' };
    }

    var next = list.find(function (iv) { return iv.start > at; });
    if (!next) { return null; }
    var startDay = Math.floor(next.start / 1440);
    var startMin = next.start % 1440;
    var openTime = formatTime(('0' + Math.floor(startMin / 60)).slice(-2) + ':' + ('0' + startMin % 60).slice(-2), data.h12);
    var diff = startDay - offset;
    if (diff === 0) {
      return next.start - at <= data.soon[1]
        ? { text: fill(labels.opensSoon, { time: openTime }), cls: 'is-closed is-opening-soon' }
        : { text: fill(labels.opensToday, { time: openTime }), cls: 'is-closed' };
    }
    if (diff === 1) { return { text: fill(labels.opensTomorrow, { time: openTime }), cls: 'is-closed' }; }
    if (diff < 7) {
      return { text: fill(labels.opensWeekday, { day: data.weekdays[weekdayOf(data.days[startDay].d)], time: openTime }), cls: 'is-closed' };
    }
    return null;
  }

  function update() {
    document.querySelectorAll('[data-store-status][data-hours]').forEach(function (el) {
      var data;
      try { data = JSON.parse(el.getAttribute('data-hours')); } catch (e) { return; }
      var result = statusText(data);
      if (!result) { return; }
      el.textContent = result.text;
      el.className = el.className.replace(/\bis-[\w-]+/g, '').trim() + ' ' + result.cls;
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', update);
  } else {
    update();
  }
  window.setInterval(update, 60000);
})();
