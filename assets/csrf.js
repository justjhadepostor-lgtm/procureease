/* CSRF protection (v79, config/csrf.php): puts this session's security token
   on everything the page sends to ProcureEase that changes something.
     · every <form method="post">: a hidden "_csrf" field (also forms added
       later, and forms sent with form.submit())
     · fetch() and XMLHttpRequest to this site, other than GET/HEAD: the
       "X-CSRF-Token" header
   Requests to other sites never get it. Loaded early in includes/head.php,
   before the page's own scripts. */
(function () {
  var meta = document.querySelector('meta[name="csrf-token"]');
  var TOKEN = meta ? meta.getAttribute('content') : '';
  if (!TOKEN) return;
  window.CSRF_TOKEN = TOKEN;

  function sameSite(url) {
    try { return new URL(url, location.href).origin === location.origin; } catch (e) { return false; }
  }
  function changes(method) {
    method = String(method || 'GET').toUpperCase();
    return method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS';
  }

  /* Forms */
  function arm(form) {
    if (!form || form.tagName !== 'FORM') return;
    if (String(form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    if (!sameSite(form.getAttribute('action') || location.href)) return;
    var f = form.querySelector('input[name="_csrf"]');
    if (!f) {
      f = document.createElement('input');
      f.type = 'hidden'; f.name = '_csrf';
      form.appendChild(f);
    }
    f.value = TOKEN;
  }
  function armAll(root) {
    if (root.tagName === 'FORM') arm(root);
    if (root.querySelectorAll) Array.prototype.forEach.call(root.querySelectorAll('form'), arm);
  }
  document.addEventListener('submit', function (e) { arm(e.target); }, true);
  var nativeSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () { arm(this); return nativeSubmit.apply(this, arguments); };
  if (HTMLFormElement.prototype.requestSubmit) {
    var nativeRequest = HTMLFormElement.prototype.requestSubmit;
    HTMLFormElement.prototype.requestSubmit = function () { arm(this); return nativeRequest.apply(this, arguments); };
  }
  function start() {
    armAll(document);
    if (window.MutationObserver) {
      new MutationObserver(function (list) {
        list.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) armAll(n); }); });
      }).observe(document.documentElement, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

  /* fetch() */
  if (window.fetch) {
    var nativeFetch = window.fetch;
    window.fetch = function (input, init) {
      try {
        var isReq = typeof Request !== 'undefined' && input instanceof Request;
        var url = isReq ? input.url : String(input);
        var method = (init && init.method) || (isReq ? input.method : 'GET');
        if (changes(method) && sameSite(url)) {
          init = Object.assign({}, init || {});
          var h = new Headers((init && init.headers) || (isReq ? input.headers : undefined));
          h.set('X-CSRF-Token', TOKEN);
          init.headers = h;
        }
      } catch (e) { /* send it as it was */ }
      return nativeFetch.call(this, input, init);
    };
  }

  /* XMLHttpRequest */
  if (window.XMLHttpRequest) {
    var open = XMLHttpRequest.prototype.open, send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
      this.__csrf = changes(method) && sameSite(url);
      return open.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
      if (this.__csrf) { try { this.setRequestHeader('X-CSRF-Token', TOKEN); } catch (e) {} }
      return send.apply(this, arguments);
    };
  }

  /* navigator.sendBeacon cannot carry a header: the token goes in the body */
  if (navigator.sendBeacon) {
    var beacon = navigator.sendBeacon.bind(navigator);
    navigator.sendBeacon = function (url, data) {
      if (sameSite(url)) {
        try {
          if (data instanceof FormData) { data.set('_csrf', TOKEN); }
          else if (data instanceof URLSearchParams) { data.set('_csrf', TOKEN); }
          else if (data == null) { data = new FormData(); data.set('_csrf', TOKEN); }
        } catch (e) {}
      }
      return beacon(url, data);
    };
  }
})();
