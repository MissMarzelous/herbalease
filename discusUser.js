// HerbalEASE discussion board
// Posts are saved in the MySQL database on the server (see api/posts.php),
// so everyone sees the same board. The author is your logged-in username.

var discussApp = {
    API: 'api/posts.php',
    posts: [],
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

    LoadPosts: function () {
        var self = this;
        return this.call('list').then(function (r) {
            self.posts = r.posts || [];

            self.user = r.user || null;
            self.UpdateForm();
            self.DisplayPosts();
            self.MoveOldBrowserPosts();
        }).catch(function (err) {
            $('#postList').empty().append($('<p>').text(err.message));
        });
    },

    // Fill in the author with the logged-in username, or ask guests to log in
    UpdateForm: function () {
        var author = $('#postAuthor');
        var button = $('#createPostForm').find('button[type=submit], input[type=submit]');
        $('#loginToPost').remove();
        if (this.user) {
            author.val(this.user).prop('readonly', true);
            button.prop('disabled', false);
        } else {
            author.val('').attr('placeholder', 'Log in to post').prop('readonly', true);
            button.prop('disabled', true);
            $('#createPostForm').prepend(
                $('<p id="loginToPost">').html('<a href="index.html#demo">Log in</a> to post. Try the demo account: <b>demo</b> / <b>HerbalTest1</b>')
            );
        }
    },

    CreatePost: function (title, content) {
        var self = this;
        return this.call('add', { title: title, content: content }).then(function () {
            return self.LoadPosts();
        });
    },


    DeletePost: function (id) {
        var self = this;
        if (!confirm('Delete this post?')) return;
        this.call('delete', { id: id }).then(function () { return self.LoadPosts(); })
            .catch(function (err) { alert(err.message); });
    },

    DisplayPosts: function () {
        var self = this;
        var postList = $('#postList');
        postList.empty(); // clear first so posts don't show up twice

        if (this.posts.length === 0) {
            postList.append($('<p>').text('No posts yet. Be the first to start a conversation!'));
            return;
        }

        this.posts.forEach(function (post) {
            // .text() shows what people typed as plain text, so it can't run as code
            var postElement = $('<div class="post"></div>').attr('data-id', post.id);
            postElement.append($('<h4>').text(post.title));
            postElement.append($('<p>').text(post.content));
            var when = post.created_at ? ' · ' + post.created_at.slice(0, 10) : '';
            postElement.append($('<p class="author">').text('By: ' + post.author + when));
            if (post.mine) {
                var del = $('<button type="button" class="btn btn-sm btn-outline-danger">Delete</button>');
                del.on('click', function () { self.DeletePost(post.id); });
                postElement.append(del);
            }
            postList.append(postElement);
        });

    },

    // One time only: posts from the old version were saved in this browser.
    // If you're logged in, offer to publish them to the shared board.
    MoveOldBrowserPosts: function () {
        var self = this;
        var old;
        try { old = JSON.parse(localStorage.getItem('discussionPosts') || '[]'); } catch (e) { old = []; }
        if (!this.user || !Array.isArray(old) || old.length === 0) return;
        if (!confirm('You have ' + old.length + ' post(s) saved in this browser from the old version. Publish them to the board under your account?')) {
            try { localStorage.removeItem('discussionPosts'); } catch (e) {}
            return;
        }
        var chain = Promise.resolve();
        old.slice().reverse().forEach(function (p) {
            chain = chain.then(function () { return self.call('add', { title: p.title, content: p.content }); });
        });
        chain.then(function () {
            try { localStorage.removeItem('discussionPosts'); } catch (e) {}
            return self.LoadPosts();
        }).catch(function (err) { alert('Some posts could not be moved: ' + err.message); });
    }
};

$(document).ready(function () {
    discussApp.LoadPosts();

    $('#createPostForm').submit(function (e) {
        e.preventDefault();

        var title = $('#postTitle').val();
        var content = $('#postContent').val();

        if (!title.trim() || !content.trim()) { alert('Please enter a title and some content.'); return; }

        discussApp.CreatePost(title, content).then(function () {
            $('#postTitle').val('');
            $('#postContent').val('');
        }).catch(function (err) { alert(err.message); });
    });
});