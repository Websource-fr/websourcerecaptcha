/**
 * Websource reCAPTCHA — injection front du widget / jeton Google reCAPTCHA.
 *
 * Principe :
 *  - les formulaires ciblés (sélecteurs CSS configurés en back-office) sont repérés au chargement puis
 *    à chaque modification du DOM (MutationObserver) : compatible formulaires rechargés en AJAX ;
 *  - le script Google n'est chargé (async/defer, hl = langue courante) que si un formulaire ciblé est
 *    présent, et pour les formulaires secondaires (newsletter, avis) seulement au premier focus ;
 *  - v2 case à cocher : un widget par formulaire, envoi bloqué tant que la case n'est pas cochée ;
 *  - v2 invisible / v3 : un widget unique par page ; à l'envoi, l'événement « submit » est intercepté
 *    en phase de capture (avant les scripts AJAX du thème et des modules), le jeton est demandé à
 *    Google, placé dans le champ caché « wsrc_token », puis le formulaire est renvoyé avec
 *    requestSubmit() : les gestionnaires AJAX existants (newsletter, avis…) reçoivent donc le jeton.
 *
 * La vérification réelle se fait côté serveur : ce script n'est qu'une aide à la saisie.
 *
 * @author    Websource
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
(function (w, d) {
  'use strict';

  var C = w.wsrcConfig;
  if (!C || !C.siteKey || !C.forms || !C.forms.length) {
    return;
  }

  var CHECKBOX = C.version === 'v2_checkbox';
  var V3 = C.version === 'v3';
  var MSG = C.msg || {};
  var CONSENT = C.consent || {};
  var STORAGE_KEY = 'wsrc_consent';

  var apiState = 0; // 0 : non chargé, 1 : chargement, 2 : prêt, 3 : échec
  var apiQueue = [];
  var pageWidget = { id: null, pending: null, used: false };
  var failure = w.wsrcFailure || null;

  if (C.hideBadge && !CHECKBOX) {
    d.documentElement.className += ' wsrc-nobadge';
  }

  // ------------------------------------------------------------------
  // Utilitaires DOM
  // ------------------------------------------------------------------

  function matches(el, selector) {
    var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
    try {
      return !!(fn && fn.call(el, selector));
    } catch (e) {
      return false;
    }
  }

  function closest(el, selector) {
    while (el && el.nodeType === 1) {
      if (matches(el, selector)) {
        return el;
      }
      el = el.parentNode;
    }
    return null;
  }

  function stop(event) {
    event.preventDefault();
    event.stopPropagation();
    if (event.stopImmediatePropagation) {
      event.stopImmediatePropagation();
    }
  }

  /**
   * Point d'insertion d'un bloc (widget, mention, message) : avant le pied de formulaire s'il existe,
   * sinon autour du bouton d'envoi.
   */
  function insertBlock(form, el) {
    var footer = form.querySelector('footer, .form-footer');
    if (footer && footer.parentNode) {
      footer.parentNode.insertBefore(el, footer);
      return;
    }
    var button = form.querySelector('[type="submit"], button:not([type])');
    if (button && button.parentNode) {
      var parent = button.parentNode;
      if (parent !== form) {
        // Le bouton partage son conteneur avec le champ (newsletter) : on se place après la ligne.
        var hasFields = parent.querySelector('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), textarea, select');
        if (hasFields) {
          parent.parentNode.insertBefore(el, parent.nextSibling);
        } else {
          parent.parentNode.insertBefore(el, parent);
        }
        return;
      }
      form.insertBefore(el, button);
      return;
    }
    form.appendChild(el);
  }

  function showError(form, message, fromServer) {
    if (!message) {
      return;
    }
    // Message serveur déjà affiché par le thème (notifications) : pas de doublon.
    if (fromServer && d.body && (d.body.innerText || '').indexOf(message) !== -1) {
      return;
    }
    clearError(form);
    var box = d.createElement('div');
    box.className = 'wsrc-error alert alert-danger';
    box.setAttribute('role', 'alert');
    box.textContent = message;
    form.insertBefore(box, form.firstChild);
  }

  function clearError(form) {
    var boxes = form.querySelectorAll('.wsrc-error');
    for (var i = 0; i < boxes.length; i++) {
      boxes[i].parentNode.removeChild(boxes[i]);
    }
  }

  function setToken(form, token) {
    var input = form.querySelector('input[name="wsrc_token"]');
    if (!input) {
      input = d.createElement('input');
      input.type = 'hidden';
      input.name = 'wsrc_token';
      form.appendChild(input);
    }
    input.value = token || '';
  }

  // ------------------------------------------------------------------
  // Consentement (optionnel)
  // ------------------------------------------------------------------

  function consentGiven() {
    if (!CONSENT.required || w.wsrcConsentGranted === true) {
      return true;
    }
    try {
      if (w.localStorage && w.localStorage.getItem(STORAGE_KEY) === '1') {
        return true;
      }
    } catch (e) { /* stockage bloqué */ }
    // Websource Cookie Consent : API window.wsConsent
    if (w.wsConsent && typeof w.wsConsent.get === 'function') {
      try {
        return !!w.wsConsent.get(CONSENT.category || 'services');
      } catch (e) { /* API indisponible */ }
    }
    return false;
  }

  function onConsent() {
    w.wsrcConsentGranted = true;
    var prompts = d.querySelectorAll('.wsrc-consent');
    for (var i = 0; i < prompts.length; i++) {
      prompts[i].parentNode.removeChild(prompts[i]);
    }
    if (apiQueue.length) {
      loadApi(null, null);
    }
  }

  function grantConsent() {
    try {
      w.localStorage.setItem(STORAGE_KEY, '1');
    } catch (e) { /* stockage bloqué : accord valable pour la page */ }
    onConsent();
  }

  function askConsent(form, then) {
    if (form.querySelector('.wsrc-consent')) {
      return;
    }
    var box = d.createElement('div');
    box.className = 'wsrc-consent';
    var text = d.createElement('p');
    text.textContent = MSG.consent || '';
    var button = d.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-secondary btn-sm';
    button.textContent = MSG.consentButton || 'OK';
    button.addEventListener('click', function () {
      grantConsent();
      if (then) {
        then();
      }
    });
    box.appendChild(text);
    box.appendChild(button);
    insertBlock(form, box);
  }

  d.addEventListener('wsconsent:update', function (event) {
    var detail = event && event.detail;
    if (detail && detail[CONSENT.category || 'services']) {
      onConsent();
    }
  });
  if (CONSENT.event) {
    d.addEventListener(CONSENT.event, onConsent);
    w.addEventListener(CONSENT.event, onConsent);
  }

  // ------------------------------------------------------------------
  // Chargement du script Google
  // ------------------------------------------------------------------

  function flushQueue(ok) {
    var queue = apiQueue;
    apiQueue = [];
    for (var i = 0; i < queue.length; i++) {
      try {
        if (ok && queue[i].ok) {
          queue[i].ok();
        } else if (!ok && queue[i].ko) {
          queue[i].ko();
        }
      } catch (e) { /* un formulaire ne doit pas bloquer les autres */ }
    }
  }

  function loadApi(ok, ko) {
    if (apiState === 2) {
      if (ok) {
        ok();
      }
      return;
    }
    if (ok || ko) {
      apiQueue.push({ ok: ok, ko: ko });
    }
    if (apiState === 1 || !consentGiven()) {
      return;
    }
    apiState = 1;
    w.wsrcOnLoad = function () {
      apiState = 2;
      flushQueue(true);
    };
    var script = d.createElement('script');
    script.src = 'https://www.google.com/recaptcha/api.js?onload=wsrcOnLoad&render=explicit&hl=' + encodeURIComponent(C.lang || 'fr');
    script.async = true;
    script.defer = true;
    script.onerror = function () {
      apiState = 3;
      flushQueue(false);
      apiState = 0; // nouvel essai possible au prochain envoi
    };
    (d.head || d.documentElement).appendChild(script);
  }

  // ------------------------------------------------------------------
  // Widgets
  // ------------------------------------------------------------------

  function renderCheckbox(form) {
    if (form.__wsrcId !== undefined || !d.documentElement.contains(form)) {
      return;
    }
    var host = d.createElement('div');
    host.className = 'wsrc-widget';
    insertBlock(form, host);
    form.__wsrcId = w.grecaptcha.render(host, {
      sitekey: C.siteKey,
      theme: C.theme || 'light',
      size: C.size || 'normal',
      callback: function () {
        clearError(form);
      }
    });
  }

  /** Widget unique (invisible / v3) : badge en bas de page, ou intégré au premier formulaire utilisé. */
  function ensurePageWidget(form) {
    if (pageWidget.id !== null) {
      return pageWidget.id;
    }
    var host = d.createElement('div');
    if (C.badge === 'inline' && form) {
      host.className = 'wsrc-badge-inline';
      insertBlock(form, host);
    } else {
      d.body.appendChild(host);
    }
    var options = {
      sitekey: C.siteKey,
      size: 'invisible',
      badge: C.badge || 'bottomright'
    };
    if (!V3) {
      options.callback = function (token) {
        var pending = pageWidget.pending;
        pageWidget.pending = null;
        if (pending) {
          pending.ok(token);
        }
      };
      options['error-callback'] = function () {
        var pending = pageWidget.pending;
        pageWidget.pending = null;
        if (pending) {
          pending.ko();
        }
      };
    }
    pageWidget.id = w.grecaptcha.render(host, options);
    return pageWidget.id;
  }

  function getToken(form, action, ok, ko) {
    loadApi(function () {
      var id;
      try {
        id = ensurePageWidget(form);
      } catch (e) {
        ko();
        return;
      }
      if (V3) {
        try {
          w.grecaptcha.execute(id, { action: action }).then(ok, ko);
        } catch (e) {
          ko();
        }
        return;
      }
      // v2 invisible : un jeton ne sert qu'une fois, on réinitialise avant chaque nouvel envoi.
      if (pageWidget.used) {
        try {
          w.grecaptcha.reset(id);
        } catch (e) { /* ignoré */ }
      }
      pageWidget.used = true;
      pageWidget.pending = { ok: ok, ko: ko };
      try {
        w.grecaptcha.execute(id);
      } catch (e) {
        pageWidget.pending = null;
        ko();
      }
    }, ko);
  }

  // ------------------------------------------------------------------
  // Formulaires
  // ------------------------------------------------------------------

  function bind(form, cfg) {
    if (form.__wsrcCfg) {
      return;
    }
    form.__wsrcCfg = cfg;
    form.className += ' wsrc-protected';

    if (!CHECKBOX) {
      setToken(form, '');
      if (C.hideBadge && C.legal && !form.querySelector('.wsrc-legal')) {
        var legal = d.createElement('p');
        legal.className = 'wsrc-legal';
        legal.innerHTML = C.legal; // HTML produit par le module (traduction échappée + liens Google)
        insertBlock(form, legal);
      }
    }

    var started = false;
    var start = function () {
      if (started) {
        return;
      }
      started = true;
      if (!consentGiven()) {
        if (CHECKBOX) {
          askConsent(form, null);
        }
      }
      if (CHECKBOX) {
        loadApi(function () { renderCheckbox(form); }, null);
      } else {
        loadApi(function () { ensurePageWidget(form); }, null);
      }
    };

    if (CHECKBOX && !cfg.lazy) {
      start();
    } else {
      form.addEventListener('focusin', start);
      form.addEventListener('pointerdown', start);
      form.addEventListener('touchstart', start);
    }

    if (failure && failure.form === cfg.key) {
      showError(form, failure.message, true);
      failure = null;
    }
  }

  function scan() {
    for (var i = 0; i < C.forms.length; i++) {
      var cfg = C.forms[i];
      var nodes;
      try {
        nodes = d.querySelectorAll(cfg.selector);
      } catch (e) {
        continue; // sélecteur invalide saisi en back-office
      }
      for (var j = 0; j < nodes.length; j++) {
        var node = nodes[j];
        if (node.nodeName === 'FORM') {
          bind(node, cfg);
        } else {
          // Le sélecteur peut viser un conteneur : on protège les formulaires qu'il contient.
          var inner = node.querySelectorAll('form');
          for (var k = 0; k < inner.length; k++) {
            bind(inner[k], cfg);
          }
        }
      }
    }
  }

  function configFor(form) {
    if (!form.__wsrcCfg) {
      scan();
    }
    if (!form.__wsrcCfg) {
      for (var i = 0; i < C.forms.length; i++) {
        if (closest(form, C.forms[i].selector)) {
          bind(form, C.forms[i]);
          break;
        }
      }
    }
    return form.__wsrcCfg || null;
  }

  /** Renvoie le formulaire en conservant le bouton d'envoi (son nom sert de déclencheur côté serveur). */
  function resubmit(form, submitter) {
    var proto = w.HTMLFormElement && w.HTMLFormElement.prototype;
    form.__wsrcPass = true;
    try {
      if (proto && typeof proto.requestSubmit === 'function') {
        if (submitter && submitter.form === form) {
          proto.requestSubmit.call(form, submitter);
        } else {
          proto.requestSubmit.call(form);
        }
      } else {
        if (submitter && submitter.name) {
          var copy = form.querySelector('input[type="hidden"][data-wsrc-submitter]');
          if (!copy) {
            copy = d.createElement('input');
            copy.type = 'hidden';
            copy.setAttribute('data-wsrc-submitter', '1');
            form.appendChild(copy);
          }
          copy.name = submitter.name;
          copy.value = submitter.value;
        }
        var event;
        try {
          event = new Event('submit', { bubbles: true, cancelable: true });
        } catch (e) {
          event = d.createEvent('Event');
          event.initEvent('submit', true, true);
        }
        if (form.dispatchEvent(event)) {
          // Appel via le prototype : un champ nommé « submit » masque form.submit().
          proto.submit.call(form);
        }
      }
    } finally {
      form.__wsrcPass = false;
    }
  }

  // Bouton utilisé (navigateurs sans event.submitter).
  d.addEventListener('click', function (event) {
    var button = closest(event.target, 'button, input[type="submit"], input[type="image"]');
    if (button && button.form) {
      button.form.__wsrcSubmitter = button;
    }
  }, true);

  // Interception en phase de capture sur window : exécutée avant les gestionnaires du thème/des modules.
  w.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || form.nodeName !== 'FORM' || form.__wsrcPass) {
      return;
    }
    var cfg = configFor(form);
    if (!cfg) {
      return;
    }
    var submitter = event.submitter || form.__wsrcSubmitter || null;

    if (!consentGiven()) {
      stop(event);
      askConsent(form, function () {
        if (CHECKBOX) {
          loadApi(function () { renderCheckbox(form); }, null);
        } else {
          resubmitWithToken(form, cfg, submitter);
        }
      });
      return;
    }

    if (CHECKBOX) {
      var response = '';
      if (apiState === 2 && form.__wsrcId !== undefined) {
        try {
          response = w.grecaptcha.getResponse(form.__wsrcId);
        } catch (e) { /* widget détruit */ }
      }
      if (!response) {
        stop(event);
        loadApi(function () { renderCheckbox(form); }, function () { showError(form, MSG.unavailable); });
        showError(form, MSG.required);
        return;
      }
      clearError(form);
      // Si un script AJAX a pris la main (preventDefault), la case est réinitialisée : un jeton ne sert qu'une fois.
      setTimeout(function () {
        if (event.defaultPrevented && form.__wsrcId !== undefined) {
          setTimeout(function () {
            try {
              w.grecaptcha.reset(form.__wsrcId);
            } catch (e) { /* ignoré */ }
          }, 1500);
        }
      }, 0);
      return;
    }

    stop(event);
    resubmitWithToken(form, cfg, submitter);
  }, true);

  function resubmitWithToken(form, cfg, submitter) {
    if (form.__wsrcBusy) {
      // v2 invisible : le visiteur a pu fermer la fenêtre de défi ; un nouveau clic la rouvre.
      if (!V3 && apiState === 2 && pageWidget.id !== null && pageWidget.pending) {
        try {
          w.grecaptcha.execute(pageWidget.id);
        } catch (e) { /* ignoré */ }
      }
      return;
    }
    form.__wsrcBusy = true;
    var done = false;
    var timer = setTimeout(function () {
      if (!done) {
        done = true;
        form.__wsrcBusy = false;
      }
    }, 30000);
    getToken(form, cfg.action, function (token) {
      if (done) {
        return;
      }
      done = true;
      clearTimeout(timer);
      form.__wsrcBusy = false;
      setToken(form, token);
      clearError(form);
      resubmit(form, submitter);
    }, function () {
      if (done) {
        return;
      }
      done = true;
      clearTimeout(timer);
      form.__wsrcBusy = false;
      showError(form, MSG.unavailable);
    });
  }

  // ------------------------------------------------------------------
  // Démarrage + formulaires ajoutés dynamiquement (AJAX, fenêtres modales, étapes du tunnel)
  // ------------------------------------------------------------------

  function boot() {
    scan();
    if (w.MutationObserver) {
      var pending = null;
      new w.MutationObserver(function () {
        if (pending) {
          return;
        }
        pending = setTimeout(function () {
          pending = null;
          scan();
        }, 150);
      }).observe(d.body, { childList: true, subtree: true });
    }
  }

  if (d.readyState === 'loading') {
    d.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window, document);
