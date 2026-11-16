/* Runs before the app starts: apply the saved colour theme (no flash of light mode),
   and keep the browser's "Install app" offer for the Install button. */
(function () {
  var t = 'light';
  try { t = localStorage.getItem('dfp_theme') || 'light'; } catch (e) {}
  document.documentElement.setAttribute('data-theme', t);
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    window.__dfpInstall = e;
    window.dispatchEvent(new Event('dfp-installable'));
  });
})();
