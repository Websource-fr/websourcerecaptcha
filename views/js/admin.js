/**
 * Websource reCAPTCHA — back-office : n'affiche que les réglages utiles à la version choisie.
 *
 * @author    Websource
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
(function () {
  'use strict';

  function group(name) {
    var field = document.querySelector('[name="' + name + '"]');
    if (!field) {
      return null;
    }
    while (field && !(field.classList && field.classList.contains('form-group'))) {
      field = field.parentNode;
    }
    return field;
  }

  function toggle(el, visible) {
    if (el) {
      el.style.display = visible ? '' : 'none';
    }
  }

  function refresh() {
    var select = document.querySelector('[name="WSRC_VERSION"]');
    if (!select) {
      return;
    }
    var version = select.value;
    var v3 = version === 'v3';
    var checkbox = version === 'v2_checkbox';

    toggle(group('WSRC_V3_THRESHOLD'), v3);
    toggle(group('WSRC_THEME'), checkbox);
    toggle(group('WSRC_SIZE'), checkbox);
    toggle(group('WSRC_BADGE'), !checkbox);
    toggle(group('WSRC_HIDE_BADGE'), !checkbox);

    var actions = document.querySelectorAll('.wsrc-v3-only');
    for (var i = 0; i < actions.length; i++) {
      toggle(actions[i], v3);
    }
  }

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    var select = document.querySelector('[name="WSRC_VERSION"]');
    if (select) {
      select.addEventListener('change', refresh);
      refresh();
    }
  });
})();

/**
 * Test complet des clés : jeton réel obtenu avec la clé du site saisie, vérifié côté serveur.
 */
(function () {
  'use strict';

  var loading = false;
  var ready = false;
  var waiting = [];

  function loadApi(lang, ok, ko) {
    if (ready) {
      ok();
      return;
    }
    waiting.push(ok);
    if (loading) {
      return;
    }
    loading = true;
    window.wsrcAdminOnLoad = function () {
      ready = true;
      var queue = waiting;
      waiting = [];
      for (var i = 0; i < queue.length; i++) {
        queue[i]();
      }
    };
    var script = document.createElement('script');
    script.src = 'https://www.google.com/recaptcha/api.js?onload=wsrcAdminOnLoad&render=explicit&hl=' + encodeURIComponent(lang || 'fr');
    script.async = true;
    script.defer = true;
    script.onerror = function () {
      loading = false;
      waiting = [];
      ko();
    };
    document.head.appendChild(script);
  }

  function value(name) {
    var field = document.querySelector('[name="' + name + '"]');
    return field ? String(field.value || '').trim() : '';
  }

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    var box = document.getElementById('wsrc-test');
    if (!box) {
      return;
    }
    var run = box.querySelector('.wsrc-test__run');
    var widgetArea = box.querySelector('.wsrc-test__widget');
    var result = box.querySelector('.wsrc-test__result');
    var timer = null;

    function show(level, message) {
      result.className = 'wsrc-test__result alert alert-' + level;
      result.textContent = message;
    }

    function send(token) {
      clearTimeout(timer);
      show('info', box.getAttribute('data-msg-running'));
      var data = new FormData();
      data.append('wsrc_test_token', token || '');
      data.append('wsrc_secret', value('WSRC_SECRET_KEY'));
      data.append('wsrc_version', value('WSRC_VERSION'));
      fetch(box.getAttribute('data-url'), {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (response) { return response.json(); })
        .then(function (json) {
          show(json.level || 'warning', json.message || box.getAttribute('data-msg-server-error'));
        })
        .catch(function () {
          show('danger', box.getAttribute('data-msg-server-error'));
        })
        .then(function () {
          run.disabled = false;
        });
    }

    run.addEventListener('click', function () {
      var siteKey = value('WSRC_SITE_KEY');
      var version = value('WSRC_VERSION') || 'v2_checkbox';
      if (!siteKey) {
        show('warning', box.getAttribute('data-msg-no-site-key'));
        return;
      }
      run.disabled = true;
      show('info', box.getAttribute('data-msg-running'));
      clearTimeout(timer);
      timer = setTimeout(function () {
        show('danger', box.getAttribute('data-msg-no-token'));
        run.disabled = false;
      }, version === 'v2_checkbox' ? 120000 : 20000);

      loadApi(box.getAttribute('data-lang'), function () {
        // Nouveau conteneur à chaque essai : la clé du site peut avoir changé.
        widgetArea.innerHTML = '';
        var host = document.createElement('div');
        widgetArea.appendChild(host);
        try {
          if (version === 'v2_checkbox') {
            show('info', box.getAttribute('data-msg-tick'));
            window.grecaptcha.render(host, { sitekey: siteKey, callback: send });
          } else if (version === 'v2_invisible') {
            var id = window.grecaptcha.render(host, { sitekey: siteKey, size: 'invisible', badge: 'inline', callback: send });
            window.grecaptcha.execute(id);
          } else {
            var id3 = window.grecaptcha.render(host, { sitekey: siteKey, size: 'invisible', badge: 'inline' });
            window.grecaptcha.execute(id3, { action: 'wsrc_admin_test' }).then(send, function () {
              show('danger', box.getAttribute('data-msg-no-token'));
              run.disabled = false;
            });
          }
        } catch (e) {
          clearTimeout(timer);
          show('danger', box.getAttribute('data-msg-no-token') + ' (' + e.message + ')');
          run.disabled = false;
        }
      }, function () {
        clearTimeout(timer);
        show('danger', box.getAttribute('data-msg-load-error'));
        run.disabled = false;
      });
    });
  });
})();
