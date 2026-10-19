// HerbalEASE user-added herbs
// Herbs are saved in the MySQL database on the server (see api/herbs.php),
// so every visitor sees the same list. Only the person who added an herb can edit or delete it.

// Show user-entered text as plain text, never as HTML
function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

var app = {
  el: document.getElementById('herbs'),
  API: 'api/herbs.php',
  herbs: [],
  user: null,

  // Talk to the server. GET for reading, POST (with the HerbalEASE header) for changes.
  call: function (action, data) {
    var opts = { method: 'GET', credentials: 'same-origin', headers: {} };
    if (action !== 'list') {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-HerbaLEASE'] = '1';
      opts.body = JSON.stringify(data || {});
    }
    return fetch(this.API + '?action=' + action, opts).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) {
        if (!res.ok) throw new Error(json.error || 'Something went wrong. Please try again.');

        return json;
      });
    }, function () {
      throw new Error("Couldn't reach the server. Check your connection and try again.");
    });
  },

  FetchAll: function () {
    var self = this;
    return this.call('list').then(function (r) {
      self.herbs = r.herbs || [];
      self.user = r.user || null;
      self.Render();
      self.MoveOldBrowserHerbs();
    }).catch(function (err) {
      self.el.innerHTML = '<tr><td colspan="6">' + escapeHtml(err.message) + '</td></tr>';
    });
  },

  Render: function () {
    var data = '';
    for (var i = 0; i < this.herbs.length; i++) {
      var h = this.herbs[i];
      data += '<tr>';
      data += '<td>' + (i + 1) + '. ' + escapeHtml(h.name) +
              '<br><small style="opacity:0.7;">added by ' + escapeHtml(h.added_by) + '</small></td>';
      data += '<td>' + escapeHtml(h.parts) + '</td>';
      data += '<td>' + escapeHtml(h.remedies) + '</td>';
      data += '<td>' + escapeHtml(h.uses) + '</td>';
      if (h.mine) {
        data += '<td><button onclick="app.Edit(' + i + ')" class="btn btn-warning">Edit</button></td>';
        data += '<td><button onclick="app.Delete(' + i + ')" class="btn btn-danger">Delete</button></td>';

      } else {
        data += '<td></td><td></td>';
      }
      data += '</tr>';
    }
    this.Count(this.herbs.length);
    this.el.innerHTML = data;
  },

  Add: function () {
    var self = this;
    var fields = {
      name: document.getElementById('add-herb').value,
      parts: document.getElementById('add-parts').value,
      remedies: document.getElementById('add-remedies').value,
      uses: document.getElementById('add-uses').value
    };
    if (!fields.name.trim() || !fields.parts.trim() || !fields.remedies.trim() || !fields.uses.trim()) {
      alert('Please fill in every field.');
      return;
    }
    if (!this.user) {
      alert('Please log in to add herbs. You can use the demo account: demo / HerbalTest1');
      return;
    }
    this.call('add', fields).then(function () {
      document.getElementById('add-herb').value = '';
      document.getElementById('add-parts').value = '';
      document.getElementById('add-remedies').value = '';
      document.getElementById('add-uses').value = '';
      return self.FetchAll();
    }).catch(function (err) { alert(err.message); });

  },

  Edit: function (item) {
    var self = this;
    var h = this.herbs[item];
    var newName = prompt('Enter the new name for the herb', h.name);
    var newParts = prompt('Enter the new parts used', h.parts);
    var newRemedies = prompt('Enter the new remedies', h.remedies);
    var newUses = prompt('Enter the new uses', h.uses);

    if (newName && newParts && newRemedies && newUses) {
      this.call('update', {
        id: h.id, name: newName, parts: newParts, remedies: newRemedies, uses: newUses
      }).then(function () { return self.FetchAll(); })
        .catch(function (err) { alert(err.message); });
    }
  },

  Delete: function (item) {
    var self = this;
    if (confirm('Are you sure you want to delete this herb?')) {
      this.call('delete', { id: this.herbs[item].id })
        .then(function () { return self.FetchAll(); })
        .catch(function (err) { alert(err.message); });
    }
  },

  // One time only: herbs from the old version were saved in this browser.
  // If you're logged in, offer to move them into your account in the database.
  MoveOldBrowserHerbs: function () {
    var self = this;
    var old;

    try { old = JSON.parse(localStorage.getItem('herbs') || '[]'); } catch (e) { old = []; }
    if (!this.user || !Array.isArray(old) || old.length === 0) return;
    if (!confirm('You have ' + old.length + ' herb(s) saved in this browser from the old version. Move them to your account so everyone can see them?')) return;

    var chain = Promise.resolve();
    old.forEach(function (h) {
      chain = chain.then(function () {
        return self.call('add', { name: h.name, parts: h.parts, remedies: h.remedies, uses: h.uses });
      });
    });
    chain.then(function () {
      try { localStorage.removeItem('herbs'); } catch (e) {}
      return self.FetchAll();
    }).catch(function (err) { alert('Some herbs could not be moved: ' + err.message); });
  },

  Count: function (data) {
    var el = document.getElementById('counter');
    var name = data === 1 ? 'Herb' : 'Herbs';
    el.innerHTML = data + ' ' + name;
  }
};

// Load herbs from the database when the page loads
window.addEventListener('load', function () {
  app.FetchAll();
});