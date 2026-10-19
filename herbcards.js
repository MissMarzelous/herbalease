// HerbalEASE shared code for herb cards, boards, and the "Save to board" dialog.
// Needs auth.js loaded first.
var HerbUI = (function () {
  "use strict";

  var API = "api/boards.php";
  var state = { user: null, boards: [] };

  // ---------- Boards API ----------
  function call(action, data) {
    var opts = { method: "GET", credentials: "same-origin", headers: {} };
    if (action !== "list") {
      opts.method = "POST";
      opts.headers["Content-Type"] = "application/json";
      opts.headers["X-HerbaLEASE"] = "1";
      opts.body = JSON.stringify(data || {});
    }
    return fetch(API + "?action=" + action, opts).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) {
        if (!res.ok) throw new Error(json.error || "Something went wrong. Please try again.");
        if (json.boards) state.boards = json.boards;
        return json;
      });
    }, function () {
      throw new Error("Couldn't reach the server. Check your connection and try again.");
    });
  }

  // Loads who is logged in and their boards
  function init() {
    return HerbAuth.current().then(function (user) {
      state.user = user;
      if (!user) return state;
      return call("list").then(function () { return state; }, function () { return state; });
    });
  }

  function boardsWith(herbName) {
    return state.boards.filter(function (b) { return b.herbs.indexOf(herbName) !== -1; });
  }

  // ---------- Card art ----------
  var PALETTE = [
    ["#2e7d32", "#66bb6a"], ["#33691e", "#8bc34a"], ["#00695c", "#4db6ac"], ["#558b2f", "#9ccc65"],
    ["#6d4c41", "#a1887f"], ["#827717", "#c0ca33"], ["#4e342e", "#8d6e63"], ["#1b5e20", "#43a047"],
    ["#00796b", "#80cbc4"], ["#5d4037", "#bcaaa4"], ["#689f38", "#aed581"], ["#7b1fa2", "#ba68c8"]
  ];

  function hashStr(s) {
    var h = 0;
    s = String(s || "");
    for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) | 0; }
    return Math.abs(h);
  }

  // Same family = same color; spelling variants like Compositae/Compositeae match
  function colorsFor(herb) {
    var fam = String(herb.Family || herb["Common Name"] || "").toLowerCase().replace(/e+ae$/, "ae").replace(/[^a-z]/g, "");
    return PALETTE[hashStr(fam) % PALETTE.length];
  }

  function gradient(herb) {
    var c = colorsFor(herb);
    return "linear-gradient(150deg, " + c[0] + ", " + c[1] + ")";
  }

  var LEAF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 19c0-8 5-13 14-14-1 9-6 14-14 14z"/><path d="M5 19c3-4 6-7 10-10"/></svg>';

  function clean(text) {
    return String(text || "").replace(/\s*\n\s*/g, " ").replace(/\s+/g, " ").trim();
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  // Builds one herb card. opts.onRemove adds a "Remove from board" button.
  function card(herb, opts) {
    opts = opts || {};
    var name = herb["Common Name"];
    var c = el("article", "herb-card");
    c.dataset.herb = name;

    var art = el("div", "herb-art");
    art.style.background = gradient(herb);
    art.style.minHeight = (120 + (hashStr(name) % 5) * 22) + "px"; // varied heights, Pinterest style
    art.innerHTML = LEAF;
    art.appendChild(el("span", "letter", name.charAt(0)));
    art.appendChild(el("h3", null, name));

    var save = el("button", "save-btn");
    save.type = "button";
    updateSaveButton(save, name);
    save.addEventListener("click", function () { openSaveDialog(herb); });
    art.appendChild(save);
    c.appendChild(art);

    var body = el("div", "herb-body");
    if (herb["Latin Name"]) body.appendChild(el("p", "latin", clean(herb["Latin Name"])));
    var meta = el("p", "meta");
    if (herb["Parts Used"]) { meta.appendChild(el("b", null, "Parts: ")); meta.appendChild(document.createTextNode(clean(herb["Parts Used"]))); }
    if (herb.Family) {
      if (herb["Parts Used"]) meta.appendChild(document.createElement("br"));
      meta.appendChild(el("b", null, "Family: ")); meta.appendChild(document.createTextNode(clean(herb.Family)));
    }
    body.appendChild(meta);

    var uses = clean(herb["Properties and Uses"]).split(",").map(function (s) { return s.trim(); }).filter(Boolean);
    if (uses.length) {
      var tags = el("div", "tags");
      uses.forEach(function (u) { tags.appendChild(el("span", "tag", u)); });
      body.appendChild(tags);
    }
    if (herb["Chemical Constituents"]) body.appendChild(el("p", "chem", "Contains: " + clean(herb["Chemical Constituents"])));

    if (opts.onRemove) {
      var rm = el("button", "remove-btn", "Remove from board");
      rm.type = "button";
      rm.addEventListener("click", function () { opts.onRemove(herb, c); });
      body.appendChild(rm);
    }
    c.appendChild(body);
    return c;
  }

  function updateSaveButton(btn, name) {
    var n = boardsWith(name).length;
    btn.textContent = n ? "Saved" : "Save";
    btn.classList.toggle("saved", n > 0);
    btn.setAttribute("aria-label", (n ? "Saved to " + n + " board" + (n > 1 ? "s" : "") + ". Change" : "Save") + " " + name);
  }

  function changed() {
    refreshSaveButtons();
    var ev;
    try { ev = new CustomEvent("herbboards:change"); } catch (e) { ev = document.createEvent("Event"); ev.initEvent("herbboards:change", false, false); }
    document.dispatchEvent(ev);
  }

  function refreshSaveButtons() {
    var cards = document.querySelectorAll(".herb-card");
    for (var i = 0; i < cards.length; i++) {
      var b = cards[i].querySelector(".save-btn");
      if (b) updateSaveButton(b, cards[i].dataset.herb);
    }
  }

  // ---------- Dialog ----------
  var lastFocus = null;

  function closeDialog() {
    var o = document.getElementById("herbDialog");
    if (o) o.remove();
    document.removeEventListener("keydown", escClose);
    if (lastFocus) lastFocus.focus();
  }

  function escClose(e) { if (e.key === "Escape") closeDialog(); }

  function shell(title, sub) {
    closeDialog();
    lastFocus = document.activeElement;
    var o = el("div", "overlay");
    o.id = "herbDialog";
    o.addEventListener("click", function (e) { if (e.target === o) closeDialog(); });
    var d = el("div", "dialog");
    d.setAttribute("role", "dialog");
    d.setAttribute("aria-modal", "true");
    d.setAttribute("aria-labelledby", "herbDialogTitle");
    var h = el("h2", null, title);
    h.id = "herbDialogTitle";
    d.appendChild(h);
    if (sub) d.appendChild(el("p", "sub", sub));
    o.appendChild(d);
    document.body.appendChild(o);
    document.addEventListener("keydown", escClose);
    return d;
  }

  function closeRow(d, label) {
    var row = el("div", "close-row");
    var x = el("button", "btn light", label || "Done");
    x.type = "button";
    x.addEventListener("click", closeDialog);
    row.appendChild(x);
    d.appendChild(row);
    return x;
  }

  function openSaveDialog(herb) {
    var name = herb["Common Name"];
    if (!state.user) {
      var d0 = shell("Save " + name, "Log in or create a free account to save herbs to your own boards.");
      var go = el("a", "btn", "Log in or sign up");
      go.href = "index.html";
      d0.appendChild(go);
      closeRow(d0, "Not now").focus();
      go.focus();
      return;
    }

    var d = shell("Save " + name, "Choose a board, or make a new one.");
    var list = el("ul", "board-pick");
    var msg = el("p", "msg");
    msg.setAttribute("role", "alert");

    function drawList() {
      list.innerHTML = "";
      if (!state.boards.length) list.appendChild(el("li", "sub", "You don't have any boards yet."));
      state.boards.forEach(function (b) {
        var on = b.herbs.indexOf(name) !== -1;
        var li = el("li");
        var btn = el("button", on ? "on" : "");
        btn.type = "button";
        btn.setAttribute("aria-pressed", on ? "true" : "false");
        btn.appendChild(el("span", null, b.name));
        btn.appendChild(el("span", "state", on ? "✓ Saved" : "Save"));
        btn.addEventListener("click", function () {
          btn.disabled = true;
          call(on ? "unsave" : "save", { board_id: b.id, herb: name }).then(function () {
            drawList(); changed();
            toast(on ? "Removed from " + b.name : "Saved to " + b.name);
          }, function (err) { msg.textContent = err.message; btn.disabled = false; });
        });
        li.appendChild(btn);
        list.appendChild(li);
      });
    }
    drawList();
    d.appendChild(list);

    var form = el("form", "new-board");
    var input = el("input");
    input.placeholder = "New board name, e.g. Tea blends";
    input.maxLength = 40;
    input.setAttribute("aria-label", "New board name");
    var add = el("button", "btn", "Create");
    add.type = "submit";
    form.appendChild(input);
    form.appendChild(add);
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      msg.textContent = "";
      add.disabled = true;
      call("create", { name: input.value, herb: name }).then(function () {
        input.value = ""; add.disabled = false;
        drawList(); changed();
        toast("Board created and " + name + " saved");
      }, function (err) { msg.textContent = err.message; add.disabled = false; });
    });
    d.appendChild(form);
    d.appendChild(msg);
    closeRow(d);
    var first = list.querySelector("button");
    (first || input).focus();
  }

  // Simple dialogs used on the boards page
  function prompt(title, value, okLabel) {
    return new Promise(function (resolve) {
      var d = shell(title);
      var form = el("form", "new-board");
      var input = el("input");
      input.value = value || "";
      input.maxLength = 40;
      input.setAttribute("aria-label", "Board name");
      var ok = el("button", "btn", okLabel || "Save");
      ok.type = "submit";
      form.appendChild(input); form.appendChild(ok);
      var msg = el("p", "msg");
      msg.setAttribute("role", "alert");
      d.appendChild(form); d.appendChild(msg);
      closeRow(d, "Cancel");
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        resolve({ value: input.value, fail: function (m) { msg.textContent = m; }, done: closeDialog });
      });
      input.focus(); input.select();
    });
  }

  function confirmBox(title, text, okLabel) {
    return new Promise(function (resolve) {
      var d = shell(title, text);
      var row = el("div", "close-row");
      var no = el("button", "btn light", "Cancel");
      var yes = el("button", "btn danger", okLabel || "Delete");
      no.type = yes.type = "button";
      no.addEventListener("click", function () { closeDialog(); resolve(false); });
      yes.addEventListener("click", function () { closeDialog(); resolve(true); });
      row.appendChild(no); row.appendChild(yes);
      d.appendChild(row);
      no.focus();
    });
  }

  var toastTimer = null;
  function toast(text) {
    var t = document.getElementById("herbToast");
    if (!t) { t = el("div", "toast"); t.id = "herbToast"; t.setAttribute("role", "status"); document.body.appendChild(t); }
    t.textContent = text;
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.hidden = true; }, 2200);
  }

  // Fills the header's user box: "Hi, name · My Boards · Log out" or "Log in"
  function userBox(box) {
    if (!box) return;
    box.innerHTML = "";
    if (state.user) {
      box.appendChild(el("span", null, "Hi, " + state.user));
      var boards = el("a", null, "My Boards"); boards.href = "boards.html"; box.appendChild(boards);
      var out = el("button", null, "Log out"); out.type = "button";
      out.addEventListener("click", function () { HerbAuth.logOut().then(function () { window.location.href = "index.html"; }); });
      box.appendChild(out);
    } else {
      var login = el("a", null, "Log in / Sign up"); login.href = "index.html"; box.appendChild(login);
    }
  }

  // Places cards in columns, each into the shortest column, so order reads left to right
  function columnsFor(width) {
    return width >= 1000 ? 4 : width >= 720 ? 3 : width >= 400 ? 2 : 1;
  }

  function masonry(container, cards) {
    var n = columnsFor(container.clientWidth || window.innerWidth);
    container.innerHTML = "";
    var cols = [], heights = [];
    for (var i = 0; i < n; i++) {
      var col = el("div", "mcol");
      container.appendChild(col);
      cols.push(col);
      heights.push(0);
    }
    cards.forEach(function (c) {
      var shortest = 0;
      for (var j = 1; j < n; j++) { if (heights[j] < heights[shortest] - 1) shortest = j; }
      cols[shortest].appendChild(c);
      heights[shortest] = cols[shortest].offsetHeight;
    });
    container._cards = cards;
    container._cols = n;
  }

  // Re-flow grids when the window size changes the column count
  var resizeTimer = null;
  window.addEventListener("resize", function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      var grids = document.querySelectorAll(".masonry");
      for (var i = 0; i < grids.length; i++) {
        var g = grids[i];
        if (g._cards && columnsFor(g.clientWidth) !== g._cols) masonry(g, g._cards);
      }
    }, 150);
  });

  function loadHerbs() {
    return fetch("herbs.json").then(function (r) { return r.json(); });
  }

  return {
    state: state, init: init, call: call, card: card, openSaveDialog: openSaveDialog,
    refreshSaveButtons: refreshSaveButtons, masonry: masonry, gradient: gradient, prompt: prompt, confirm: confirmBox,
    closeDialog: closeDialog, toast: toast, userBox: userBox, loadHerbs: loadHerbs, clean: clean
  };
})();
