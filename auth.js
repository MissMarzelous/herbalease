// HerbalEASE accounts
// Accounts are stored in a MySQL database on the server (see api/auth.php).
// Passwords are checked and hashed on the server with PHP's password_hash(),
// so they are never stored or visible in the browser.
var HerbAuth = (function () {
  "use strict";

  var API = "api/auth.php";

  // Same rules the server enforces, used here to give instant feedback
  var RULES = [
    { id: "len", text: "At least 8 characters", test: function (p) { return p.length >= 8; } },
    { id: "letter", text: "At least one letter", test: function (p) { return /[A-Za-z]/.test(p); } },
    { id: "number", text: "At least one number", test: function (p) { return /[0-9]/.test(p); } },
    { id: "user", text: "Doesn't contain your username", test: function (p, u) {
        u = String(u || "").trim().toLowerCase();
        return p.length > 0 && (u.length === 0 || p.toLowerCase().indexOf(u) === -1);
      } }
  ];

  function call(action, data) {
    var opts = { method: "GET", credentials: "same-origin", headers: {} };
    if (action !== "me") {
      opts.method = "POST";
      opts.headers["Content-Type"] = "application/json";
      opts.headers["X-HerbaLEASE"] = "1";
      opts.body = JSON.stringify(data || {});
    }
    return fetch(API + "?action=" + action, opts).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) {
        if (!res.ok) throw new Error(json.error || "Something went wrong. Please try again.");
        return json;
      });
    }, function () {
      throw new Error("Couldn't reach the server. Check your connection and try again.");
    });
  }

  function signUp(username, password) {
    return call("signup", { username: username, password: password }).then(function (r) { return r.user; });
  }

  function logIn(username, password) {
    return call("login", { username: username, password: password }).then(function (r) { return r.user; });
  }

  // Resolves to the logged-in username, or null
  function current() {
    return call("me").then(function (r) { return r.user || null; }, function () { return null; });
  }

  function logOut() {
    return call("logout").catch(function () { /* ignore */ });
  }

  // On app pages: turn the "Log in" menu item into "Log out (name)" when signed in
  function updateMenu() {
    var link = document.querySelector('.horizontal-menu a[href="index.html"]');
    if (!link) return;
    current().then(function (user) {
      if (!user) return;
      link.textContent = "Log out (" + user + ")";
      link.addEventListener("click", function (e) {
        e.preventDefault();
        logOut().then(function () { window.location.href = "index.html"; });
      });
    });
  }

  document.addEventListener("DOMContentLoaded", updateMenu);

  return { signUp: signUp, logIn: logIn, current: current, logOut: logOut, rules: RULES };
})();
