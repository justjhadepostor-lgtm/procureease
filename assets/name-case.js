/* v104: a name as it is written, while it is typed — "JHADE POSTOR" / "jhade postor"
   → "Jhade Postor". The same rule as name_case() in config/auth.php: each part
   capitalised (also after a hyphen or apostrophe: Ana-Marie, D'Souza), an
   initial and II / III / IV in capitals. Only the case changes, never the
   length, so the caret stays where it was. */
window.peNameCase = function (v) {
  var up = function (w) {
    var single, roman = /^(ii|iii|iv)[.,]?$/i;
    try { single = /^\p{L}\.?$/u; } catch (e) { single = /^[A-Za-zÀ-ÖØ-öø-ÿÑñ]\.?$/; }
    if (single.test(w) || roman.test(w)) return w.toUpperCase();
    var low = w.toLowerCase();
    try { return low.replace(/(^|[-'’])(\p{L})/gu, function (m, a, b) { return a + b.toUpperCase(); }); }
    catch (e) { return low.replace(/(^|[-'’])([A-Za-zÀ-ÖØ-öø-ÿÑñ])/g, function (m, a, b) { return a + b.toUpperCase(); }); }
  };
  return String(v).replace(/\S+/g, up);
};
