(function () {
    var script = document.currentScript;
    var tickUrl = script && script.getAttribute('data-tick-url');

    if (!tickUrl) {
        return;
    }

    function tick() {
        if (document.hidden) {
            return;
        }

        fetch(tickUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).catch(function () {});
    }

    tick();
    setInterval(tick, 20000);
})();
