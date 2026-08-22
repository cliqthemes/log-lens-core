/*!
 * Log Lens browser SDK — first-hand capture of frontend errors.
 *
 * Embed on any page:
 *   <script src="https://logs.example.com/loglens.js"
 *           data-ingest-url="https://logs.example.com/?api=ingest&app=web"
 *           data-key="llk_…"
 *           data-release="v2.4.0"></script>
 *
 * It captures uncaught errors and unhandled promise rejections, and exposes
 * window.LogLens.captureException(err) / captureMessage(msg, severity).
 *
 * The ingest key is a write-only push token; it can only
 * add events, so exposing it in the browser is expected.
 */
(function () {
  "use strict";
  var script = document.currentScript || {};
  var cfg = window.__LOGLENS__ || {};
  var attr = function (name) { return script.getAttribute ? script.getAttribute(name) : null; };
  var url = cfg.url || attr("data-ingest-url") || "";
  var key = cfg.key || attr("data-key") || "";
  var release = cfg.release || attr("data-release") || "";
  var environment = cfg.environment || attr("data-environment") || "production";
  if (!url) { return; }

  // The key rides in the query string and the body is sent as a simple content
  // type, so cross-origin posts need no CORS preflight (the reporter never reads
  // the response).
  var target = url + (url.indexOf("?") === -1 ? "?" : "&") + "key=" + encodeURIComponent(key);
  function send(event) {
    try {
      var body = JSON.stringify({ events: [event] });
      if (navigator.sendBeacon && navigator.sendBeacon(target, new Blob([body], { type: "text/plain" }))) {
        return;
      }
      fetch(target, {
        method: "POST",
        headers: { "Content-Type": "text/plain" },
        body: body,
        keepalive: true,
        mode: "no-cors",
      }).catch(function () {});
    } catch (e) { /* never throw from the reporter */ }
  }

  function base(extra) {
    var event = {
      channel: "browser",
      environment: environment,
      request: { url: location.href },
      context: { user_agent: navigator.userAgent, referrer: document.referrer || undefined },
    };
    if (release) { event.release = release; }
    for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) { event[k] = extra[k]; } }
    return event;
  }

  function fromError(error, fallbackMessage) {
    if (error && (error.stack || error.message)) {
      return base({
        message: error.message || fallbackMessage || "Unknown error",
        exception_class: error.name || "Error",
        stack: error.stack || "",
        severity: "ERROR",
      });
    }
    return base({ message: fallbackMessage || "Unknown error", severity: "ERROR" });
  }

  window.LogLens = {
    captureException: function (error, extra) { send(Object.assign(fromError(error), extra || {})); },
    captureMessage: function (message, severity, extra) {
      send(Object.assign(base({ message: String(message), severity: (severity || "INFO").toUpperCase() }), extra || {}));
    },
  };

  window.addEventListener("error", function (event) {
    send(fromError(event.error, event.message));
  });
  window.addEventListener("unhandledrejection", function (event) {
    var reason = event.reason;
    if (reason instanceof Error) { send(fromError(reason, "Unhandled promise rejection")); }
    else { send(base({ message: "Unhandled promise rejection: " + String(reason), exception_class: "UnhandledRejection", severity: "ERROR" })); }
  });
})();
