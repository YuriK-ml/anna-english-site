(function () {
  "use strict";

  var API_URL = "/api/chat.php";

  var TRANSCRIBE_URL = "/api/transcribe.php";

  var SPEECH_URL = "/api/speech.php";

  var AUTH_URL = "/tutor/auth.php";
  var SESSION_URL = "/tutor/session.php";
  var DEMO_URL = "/tutor/demo.php";
  var TTS_RATES = [0.75, 0.85, 1, 1.15];
  var DEFAULT_TTS_RATE = 0.85;
  var IMAGE_MAX_DIM = 1024;
  var IMAGE_MAX_BYTES = 900 * 1024;
  var ALLOWED_IMAGE_MIME = ["image/png", "image/jpeg", "image/webp"];
  var STT_MIME_CANDIDATES = [
    "audio/webm;codecs=opus",
    "audio/webm",
    "audio/ogg;codecs=opus",
    "audio/ogg",
  ];

  var LEVELS = ["A1", "A2", "B1", "B2", "C1", "C2"];
  var DEFAULT_LEVEL = "A2";

  var TOPICS = [
    {
      id: "restaurant",
      emoji: "🍽️",
      title: "Restaurant",
      subtitle: "Общение с официантом",
      starter: "Good evening! Welcome to our restaurant.\nDo you have a reservation?",
    },
    {
      id: "coffee",
      emoji: "☕",
      title: "Coffee Shop",
      subtitle: "Заказ кофе",
      starter: "Hi! What can I get for you today?",
    },
    {
      id: "supermarket",
      emoji: "🛒",
      title: "Supermarket",
      subtitle: "Покупки",
      starter: "Hello! Can I help you find something?",
    },
    {
      id: "hotel",
      emoji: "🏨",
      title: "Hotel",
      subtitle: "Заселение и общение в отеле",
      starter: "Good evening! Welcome to our hotel.\nDo you have a reservation?",
    },
    {
      id: "airport",
      emoji: "✈️",
      title: "Airport",
      subtitle: "Регистрация и аэропорт",
      starter: "Good morning! May I see your passport and ticket, please?",
    },
    {
      id: "job",
      emoji: "💼",
      title: "Job Interview",
      subtitle: "Собеседование",
      starter: "Good afternoon. Thank you for coming today.\nCould you introduce yourself?",
    },
    {
      id: "doctor",
      emoji: "👨‍⚕️",
      title: "Doctor",
      subtitle: "Посещение врача",
      starter: "Hello. What seems to be the problem today?",
    },
    {
      id: "taxi",
      emoji: "🚕",
      title: "Taxi",
      subtitle: "Поездка и разговор с водителем",
      starter: "Hi! Where would you like to go?",
    },
    {
      id: "smalltalk",
      emoji: "👥",
      title: "Small Talk",
      subtitle: "Неформальное общение",
      starter: "Hi! How are you today?",
    },
    {
      id: "cinema",
      emoji: "🎬",
      title: "Cinema",
      subtitle: "Покупка билетов и обсуждение фильма",
      starter: "Hello! What movie would you like to watch?",
    },
    {
      id: "phone",
      emoji: "📞",
      title: "Phone Call",
      subtitle: "Телефонный разговор",
      starter: "Hello! This is Alex speaking. Who is calling?",
    },
    {
      id: "travel",
      emoji: "🏖️",
      title: "Travel",
      subtitle: "Общение во время путешествия",
      starter: "Hi! Where are you traveling from?",
    },
    {
      id: "gaming",
      emoji: "🎮",
      title: "Gaming",
      subtitle: "Компьютерные игры",
      cardTitle: "Gaming — Компьютерные игры",
      desc: "Играй, обсуждай стратегии и изучай английский",
      starter: "Hi! What game would you like to play or talk about today?",
    },

  ];

  var LS = {
    level: "tutor_level",
    lang: "tutor_analysis_lang",
    strict: "tutor_analysis_strictness",
    ttsRate: "tutor_tts_rate"
  };

  function $(id) {
    return document.getElementById(id);
  }

function isStandaloneMode() {
  try {
    var params = new URLSearchParams(location.search || '');
    return params.get('mode') === 'standalone';
  } catch (e) {
    return /(?:\?|&)mode=standalone(?:&|$)/.test(location.search || '');
  }
}

function applyNavUi() {
  var top = document.querySelector('.tutor-hero__top');
  var back = document.getElementById('backToSiteLink');
  var logout = document.getElementById('logoutLink');

  var view = (typeof state === 'object' && state && typeof state.view === 'string') ? state.view : 'home';
  var mode = (state && state.access && typeof state.access.mode === 'string') ? state.access.mode : 'guest';
  var standalone = isStandaloneMode();

  var showBack = (view === 'home') && !standalone;
  var showLogout = (view === 'home') && (mode === 'student');

  if (back) back.style.display = showBack ? '' : 'none';
  if (logout) {
    logout.style.display = showLogout ? '' : 'none';
    logout.setAttribute('href', standalone ? '/tutor/logout.php?mode=standalone' : '/tutor/logout.php');
  }

  if (top) top.style.display = showBack ? '' : 'none';
}

function applyStandaloneMode() {
  applyNavUi();
}

  function safeJsonParse(raw, fallback) {
    try {
      var parsed = JSON.parse(raw);
      return parsed == null ? fallback : parsed;
    } catch (e) {
      return fallback;
    }
  }

  function getSelectedLevel() {
    var raw = localStorage.getItem(LS.level);
    return LEVELS.indexOf(raw) >= 0 ? raw : DEFAULT_LEVEL;
  }

  function setSelectedLevel(level) {
    localStorage.setItem(LS.level, level);
  }

  function getAnalysisLang() {
    var raw = localStorage.getItem(LS.lang);
    return raw === "en" ? "en" : "ru";
  }

  function setAnalysisLang(lang) {
    localStorage.setItem(LS.lang, lang);
  }

  function getStrictness() {
    var raw = localStorage.getItem(LS.strict);
    if (raw === "friendly" || raw === "strict") return raw;
    return "normal";
  }

  function setStrictness(value) {
    localStorage.setItem(LS.strict, value);
  }


  function getTtsRate() {
    var raw = localStorage.getItem(LS.ttsRate);
    var v = parseFloat(String(raw || ""));
    for (var i = 0; i < TTS_RATES.length; i++) {
      if (TTS_RATES[i] === v) return v;
    }
    return DEFAULT_TTS_RATE;
  }

  function setTtsRate(rate) {
    var v = parseFloat(String(rate));
    var ok = false;
    for (var i = 0; i < TTS_RATES.length; i++) {
      if (TTS_RATES[i] === v) ok = true;
    }
    if (!ok) v = DEFAULT_TTS_RATE;

    localStorage.setItem(LS.ttsRate, String(v));
    if (state && state.tts) {
      state.tts.playbackRate = v;
      if (state.tts.audio) {
        try { state.tts.audio.playbackRate = v; } catch (e) {}
      }
    }
    syncTtsRateButtons();
  }

  function syncTtsRateButtons() {
    var root = document.getElementById("ttsRateWrap");
    if (!root) return;
    var btns = root.querySelectorAll(".tutor-toggle__btn");
    if (!btns || !btns.length) return;

    var active = (state && state.tts && typeof state.tts.playbackRate === "number")
      ? state.tts.playbackRate
      : getTtsRate();

    for (var i = 0; i < btns.length; i++) {
      var b = btns[i];
      var r = parseFloat(String(b.getAttribute("data-rate") || ""));
      if (r === active) b.classList.add("is-active");
      else b.classList.remove("is-active");
    }
  }

  function historyKey(topicId, level) {
    // Keep demo conversations separate from student history in localStorage.
    if (state && state.access && state.access.mode === "demo" && state.access.demoId) {
      return "tutor_demo_" + state.access.demoId + "_history_" + topicId + "_" + level;
    }
    return "tutor_history_" + topicId + "_" + level;
  }

  function normalizeLocalHistoryItem(m) {
    if (!m || typeof m !== "object") return null;
    var role = m.role;
    if (role === "ai") role = "assistant"; // backward compatibility
    if (role !== "assistant" && role !== "user") return null;
    var text = typeof m.text === "string" ? m.text : "";
    text = text.trim();
    if (!text) return null;
    return {
      role: role,
      text: text,
      ts: typeof m.ts === "number" ? m.ts : Date.now(),
    };
  }

  function loadHistory(topicId, level) {
    var raw = localStorage.getItem(historyKey(topicId, level));
    var data = safeJsonParse(raw, []);
    if (!Array.isArray(data)) return [];
    var out = [];
    for (var i = 0; i < data.length; i++) {
      var n = normalizeLocalHistoryItem(data[i]);
      if (n) out.push(n);
    }
    return out;
  }

  function saveHistory(topicId, level, messages) {
    localStorage.setItem(historyKey(topicId, level), JSON.stringify(messages));
  }

  function ensureStarter(topic, messages) {
    if (!messages || messages.length === 0) {
      return [
        {
          role: "assistant",
          text: topic.starter,
          ts: Date.now(),
        },
      ];
    }
    return messages;
  }

  function hasUserMessage(messages) {
    for (var i = messages.length - 1; i >= 0; i--) {
      if (messages[i] && messages[i].role === "user") return true;
    }
    return false;
  }

  function lastUserText(messages) {
    for (var i = messages.length - 1; i >= 0; i--) {
      if (messages[i] && messages[i].role === "user") return messages[i].text || "";
    }
    return "";
  }

  function showToast(text) {
    var toast = $("toast");
    if (!toast) return;
    toast.textContent = text;
    toast.hidden = false;
    clearTimeout(showToast._t);
    showToast._t = setTimeout(function () {
      toast.hidden = true;
    }, 2400);
  }


  function ensureAuthUi() {
    if (document.getElementById("authOverlay")) return;

    var overlay = document.createElement("div");
    overlay.id = "authOverlay";
    overlay.className = "tutor-auth";
    overlay.hidden = true;

    overlay.innerHTML =
      '<div class="tutor-auth__backdrop"></div>' +
      '<div class="tutor-auth__card" role="dialog" aria-modal="true" aria-label="Доступ к Tutor">' +
      '<div class="tutor-auth__title">Введите код доступа</div>' +
      '<div class="tutor-auth__text">Чтобы пользоваться AI-функциями, нужен персональный код.</div>' +
      '<input id="authCode" class="tutor-auth__input" inputmode="text" autocomplete="one-time-code" placeholder="Код" />' +
      '<div class="tutor-auth__demo">' +
      '  <div class="tutor-auth__demoText">5 бесплатных текстовых сообщений без регистрации.</div>' +
      '  <button id="authDemoStart" class="tutor-btn tutor-btn--ghost" type="button">Попробовать бесплатно</button>' +
      '</div>' +
      '<div class="tutor-auth__row">' +
      '<button id="authSubmit" class="tutor-btn" type="button">Открыть доступ</button>' +
      '<button id="authCancel" class="tutor-btn tutor-btn--ghost" type="button">Отмена</button>' +
      '</div>' +
      '<div id="authError" class="tutor-auth__error" hidden></div>' +
      '</div>';

    document.body.appendChild(overlay);

    function close() {
      overlay.hidden = true;
      var err = document.getElementById("authError");
      if (err) {
        err.hidden = true;
        err.textContent = "";
      }
    }

    overlay.querySelector('.tutor-auth__backdrop').addEventListener('click', function (e) {
      var t = parseInt(overlay.getAttribute("data-opened-at") || "0", 10) || 0;
      if (t && Date.now() - t < 350) return;
      close();
    });

    document.getElementById('authCancel').addEventListener('click', function () {
      close();
    });

    document.getElementById('authSubmit').addEventListener('click', function () {
      submitAuthCode();
    });

    var demoBtn = document.getElementById('authDemoStart');
    if (demoBtn) {
      demoBtn.addEventListener('click', function () {
        startDemo();
      });
    }

    document.getElementById('authCode').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        submitAuthCode();
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        close();
      }
    });
  }

  function openAuthModal(message) {
    ensureAuthUi();
    var overlay = document.getElementById("authOverlay");
    if (!overlay) return;
    overlay.hidden = false;
    overlay.setAttribute("data-opened-at", String(Date.now()));

    applyAccessUi();

    var err = document.getElementById("authError");
    if (err) {
      if (message) {
        err.textContent = message;
        err.hidden = false;
      } else {
        err.textContent = "";
        err.hidden = true;
      }
    }

    var input = document.getElementById("authCode");
    if (input) {
      input.value = "";
      setTimeout(function () {
        try {
          input.focus();
        } catch (e) {}
      }, 20);
    }
  }

  function submitAuthCode() {
    var btn = document.getElementById('authSubmit');
    var input = document.getElementById('authCode');
    var err = document.getElementById('authError');
    if (!input) return;

    var code = (input.value || '').trim();
    if (!code) {
      if (err) {
        err.textContent = 'Введите код доступа.';
        err.hidden = false;
      }
      return;
    }

    if (btn) btn.disabled = true;

    fetch(AUTH_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ code: code }),
    })
      .then(function (res) {
        return res
          .json()
          .catch(function () {
            return null;
          })
          .then(function (data) {
            return { ok: res.ok, status: res.status, data: data };
          });
      })
      .then(function (res) {
        if (!res || !res.ok) {
          var msg =
            res && res.data && res.data.error && typeof res.data.error.message === 'string'
              ? res.data.error.message
              : 'Не удалось проверить код.';
          if (err) {
            err.textContent = msg;
            err.hidden = false;
          }
          return;
        }

        var overlay = document.getElementById('authOverlay');
        if (overlay) overlay.hidden = true;
        showToast('Доступ открыт. Можно продолжать.');
        syncAccessFromServer();
      })
      .catch(function (e) {
        console.error('auth error', e);
        if (err) {
          err.textContent = 'Не удалось проверить код.';
          err.hidden = false;
        }
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function isAuthRequiredResponse(res) {
    return !!(
      res &&
      res.status === 401 &&
      res.data &&
      res.data.error &&
      res.data.error.code === 'auth_required'
    );
  }

  function demoExhausted() {
    return !!(state && state.access && state.access.mode === "demo" && (state.access.remaining | 0) <= 0);
  }

  function applyAccessUi() {
    if (!state || !state.access) return;
    var mode = state.access.mode;

    var mic = $("micBtn");
    if (mic) {
      if (mode === "demo") {
        mic.disabled = true;
      } else {
        // Avoid overriding mic button state during recording/transcribing flows.
        if (state.stt && state.stt.mode === "idle" && !state.stt.busy) mic.disabled = false;
      }
    }

    var sendBtn = $("sendBtn");
    if (sendBtn) {
      sendBtn.disabled = !!state.busy || demoExhausted();
    }

    // Demo status in the existing hint line.
    if (mode === "demo") {
      var rem = (state.access.remaining | 0);
      if (rem > 0) {
        setVoiceHint("Демо: осталось " + rem + " из 5. В демо доступен только текст.", null);
      } else {
        setVoiceHint("Демо-лимит исчерпан. Введите код доступа, чтобы продолжить.", "is-error");
      }
    }

    // Hide demo CTA inside auth modal once demo is active (or student is authorized).
    var demoBtn = document.getElementById("authDemoStart");
    if (demoBtn) {
      demoBtn.style.display = mode === "guest" ? "" : "none";
      demoBtn.disabled = false;
    }

    applyNavUi();
  }

  function setAccess(mode, demoId, remaining) {
    if (!state || !state.access) return;
    state.access.mode = mode;
    state.access.demoId = demoId || "";
    state.access.remaining = typeof remaining === "number" ? remaining : (state.access.remaining | 0);
    applyAccessUi();
  }

  function syncAccessFromServer() {
    return fetch(SESSION_URL, { method: "GET", cache: "no-store", credentials: "same-origin" })
      .then(function (res) {
        return res
          .json()
          .catch(function () { return null; })
          .then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
      })
      .then(function (res) {
        if (!res || !res.ok || !res.data || typeof res.data !== "object") return;
        var mode = typeof res.data.mode === "string" ? res.data.mode : "guest";
        if (mode === "student") {
          setAccess("student", "", 0);
          // If we are inside a chat, reload student history key after login.
          if (state.view === "chat" && state.topicId) {
            var lvl = getSelectedLevel();
            var t = findTopic(state.topicId);
            if (t) {
              var msgs = ensureStarter(t, loadHistory(state.topicId, lvl));
              saveHistory(state.topicId, lvl, msgs);
              renderMessages(msgs);
            }
          }
          return;
        }
        if (mode === "demo") {
          var rem = parseInt(String(res.data.remaining || 0), 10) || 0;
          var id = typeof res.data.demoId === "string" ? res.data.demoId : "";
          setAccess("demo", id, rem);
          if (rem <= 0) {
            // Demo exhausted: allow entering the access code (also after refresh).
            setTimeout(function () {
              openAuthModal("Демо-лимит исчерпан. Введите персональный код доступа, чтобы продолжить.");
            }, 0);
          }
          return;
        }
        setAccess("guest", "", 0);
      })
      .catch(function () {});
  }

  function startDemo() {
    if (!state || !state.access) return;
    if (state.access.mode !== "guest") return;

    var btn = document.getElementById("authDemoStart");
    if (btn) btn.disabled = true;

    fetch(DEMO_URL, { method: "POST", cache: "no-store", credentials: "same-origin" })
      .then(function (res) {
        return res
          .json()
          .catch(function () { return null; })
          .then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
      })
      .then(function (res) {
        if (!res || !res.ok || !res.data || typeof res.data !== "object") {
          throw new Error("Не удалось запустить демо.");
        }
        if (res.data.mode === "student") {
          setAccess("student", "", 0);
          var overlay = document.getElementById("authOverlay");
          if (overlay) overlay.hidden = true;
          return;
        }
        if (res.data.mode !== "demo") {
          throw new Error("Не удалось запустить демо.");
        }
        var rem = parseInt(String(res.data.remaining || 0), 10) || 0;
        var id = typeof res.data.demoId === "string" ? res.data.demoId : "";
        setAccess("demo", id, rem);

        // Reset chat view to demo history (do not mix with student history).
        if (state.view === "chat" && state.topicId) {
          var lvl = getSelectedLevel();
          var t = findTopic(state.topicId);
          if (t) {
            var msgs = ensureStarter(t, loadHistory(state.topicId, lvl));
            saveHistory(state.topicId, lvl, msgs);
            renderMessages(msgs);
            renderAnalysisEmpty();
          }
        }

        var overlay = document.getElementById("authOverlay");
        if (overlay) overlay.hidden = true;
        showToast("Демо включено. Можно отправить до 5 сообщений.");
        try { $("chatInput").focus(); } catch (e) {}
      })
      .catch(function (e) {
        console.error("demo start error", e);
        var err = document.getElementById("authError");
        if (err) {
          err.textContent = (e && e.message) ? e.message : "Не удалось запустить демо.";
          err.hidden = false;
        }
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function hintEl() {
    return document.querySelector(".tutor-hint");
  }

  function setVoiceHint(text, kind) {
    var el = hintEl();
    if (!el) return;
    el.textContent = text;
    el.classList.remove("is-error", "is-success", "is-recording", "is-busy");
    if (kind) el.classList.add(kind);
  }

  function voiceHintReady() {
    setVoiceHint("Можно говорить или вводить текст с клавиатуры.", null);
    applyAccessUi();
  }


  function ensureTtsDisclosure() {
    var hint = hintEl();
    if (!hint || !hint.parentElement) return;

    var root = document.getElementById("tutorTtsDisclosure");
    if (!root) {
      root = document.createElement("div");
      root.id = "tutorTtsDisclosure";
      root.className = "tutor-disclosure";

      var text = document.createElement("span");
      text.className = "tutor-disclosure__text";
      text.textContent = "Голос AI-сгенерирован.";
      root.appendChild(text);

      hint.parentElement.appendChild(root);
    }
  }

  function ensureTtsRateControl() {
    var restart = $("restartBtn");
    if (!restart || !restart.parentElement) return;

    var bar = restart.parentElement;
    var wrap = document.getElementById("ttsRateWrap");
    if (!wrap) {
      wrap = document.createElement("div");
      wrap.id = "ttsRateWrap";
      wrap.className = "tutor-chatbar__tts";

      var toggle = document.createElement("div");
      toggle.className = "tutor-toggle";

      var labels = {
        0.75: "0.75×",
        0.85: "0.85×",
        1: "1×",
        1.15: "1.15×",
      };

      for (var i = 0; i < TTS_RATES.length; i++) {
        var r = TTS_RATES[i];
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "tutor-toggle__btn";
        btn.textContent = labels[r] || String(r) + "×";
        btn.setAttribute("data-rate", String(r));
        btn.addEventListener("click", function (e) {
          e.preventDefault();
          var v = parseFloat(String(this.getAttribute("data-rate") || ""));
          setTtsRate(v);
        });
        toggle.appendChild(btn);
      }

      wrap.appendChild(toggle);
      bar.insertBefore(wrap, restart);
    }

    syncTtsRateButtons();
  }
  function ensureImageComposerUI() {
    var input = $("chatInput");
    if (!input) return;
    var host = input.closest ? input.closest(".tutor-chat") : null;
    if (!host) return;

    var el = document.getElementById("composeImage");
    if (!el) {
      el = document.createElement("div");
      el.id = "composeImage";
      el.className = "tutor-compose";
      el.hidden = true;
      var hint = host.querySelector(".tutor-hint");
      host.insertBefore(el, hint || null);
    }
  }

  function clearComposeImage() {
    if (!state.compose) return;
    state.compose.imageDataUrl = null;
    state.compose.imageMime = "";
    state.compose.imageBusy = false;
    renderComposeImage();
  }

  function renderComposeImage() {
    var el = document.getElementById("composeImage");
    if (!el) return;

    var has = !!(state.compose && state.compose.imageDataUrl);
    var busy = !!(state.compose && state.compose.imageBusy);

    if (!has && !busy) {
      el.hidden = true;
      el.innerHTML = "";
      return;
    }

    el.hidden = false;
    el.innerHTML = "";

    if (busy) {
      var b = document.createElement("div");
      b.className = "tutor-compose__busy";
      b.textContent = "Обрабатываем изображение…";
      el.appendChild(b);
      return;
    }

    var row = document.createElement("div");
    row.className = "tutor-compose__row";

    var img = document.createElement("img");
    img.className = "tutor-compose__thumb";
    img.alt = "Вставленное изображение";
    img.src = state.compose.imageDataUrl;

    var actions = document.createElement("div");
    actions.className = "tutor-compose__actions";

    var meta = document.createElement("div");
    meta.className = "tutor-compose__meta";
    meta.textContent = "Изображение будет отправлено вместе с сообщением.";

    var del = document.createElement("button");
    del.type = "button";
    del.className = "tutor-compose__remove";
    del.textContent = "Удалить";
    del.addEventListener("click", function (e) {
      e.preventDefault();
      clearComposeImage();
    });

    actions.appendChild(meta);
    actions.appendChild(del);

    row.appendChild(img);
    row.appendChild(actions);
    el.appendChild(row);
  }

  function fileToDataUrl(file) {
    return new Promise(function (resolve, reject) {
      var r = new FileReader();
      r.onload = function () {
        resolve(String(r.result || ""));
      };
      r.onerror = function () {
        reject(new Error("read_failed"));
      };
      r.readAsDataURL(file);
    });
  }

  function loadImageFromDataUrl(url) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () { resolve(img); };
      img.onerror = function () { reject(new Error("image_load_failed")); };
      img.src = url;
    });
  }

  function canvasToBlob(canvas, mime, quality) {
    return new Promise(function (resolve) {
      if (!canvas || typeof canvas.toBlob !== "function") return resolve(null);
      try {
        canvas.toBlob(function (blob) {
          resolve(blob || null);
        }, mime, quality);
      } catch (e) {
        resolve(null);
      }
    });
  }

  function downscaleImageFile(file) {
    // Returns Promise<{ dataUrl, mime, bytes }>
    return fileToDataUrl(file)
      .then(function (dataUrl) {
        return loadImageFromDataUrl(dataUrl).then(function (img) {
          var w = img.naturalWidth || img.width || 0;
          var h = img.naturalHeight || img.height || 0;
          if (!w || !h) throw new Error("bad_image");

          var maxSide = Math.max(w, h);
          var scale = maxSide > IMAGE_MAX_DIM ? (IMAGE_MAX_DIM / maxSide) : 1;
          var tw = Math.max(1, Math.round(w * scale));
          var th = Math.max(1, Math.round(h * scale));

          var canvas = document.createElement("canvas");
          canvas.width = tw;
          canvas.height = th;
          var ctx = canvas.getContext("2d");
          if (!ctx) throw new Error("no_canvas");
          ctx.drawImage(img, 0, 0, tw, th);

          // Prefer smaller formats. If webp unsupported, fall back to jpeg.
          var outMime = "image/webp";
          var qList = [0.86, 0.76, 0.66, 0.56];

          function tryEncode(mimeIndex, qIndex) {
            var mime = mimeIndex === 0 ? outMime : "image/jpeg";
            var q = qList[qIndex] || 0.76;

            return canvasToBlob(canvas, mime, q).then(function (blob) {
              if (!blob) {
                // fallback: toDataURL
                var du;
                try { du = canvas.toDataURL(mime, q); } catch (e) { du = ""; }
                if (!du) throw new Error("encode_failed");
                return { dataUrl: du, mime: mime, bytes: du.length };
              }

              if (blob.size > IMAGE_MAX_BYTES && qIndex < qList.length - 1) {
                return tryEncode(mimeIndex, qIndex + 1);
              }
              if (blob.size > IMAGE_MAX_BYTES && mimeIndex === 0) {
                // try jpeg if webp still too large
                return tryEncode(1, 0);
              }

              return fileToDataUrl(blob).then(function (du2) {
                return { dataUrl: du2, mime: mime, bytes: blob.size };
              });
            });
          }

          return tryEncode(0, 0);
        });
      });
  }

  function setComposeImageFromFile(file) {
    if (!state.compose) return;
    ensureImageComposerUI();

    if (!file || !file.type || ALLOWED_IMAGE_MIME.indexOf(file.type) < 0) {
      showToast("Поддерживаются PNG/JPEG/WEBP.");
      return;
    }

    state.compose.imageBusy = true;
    renderComposeImage();

    downscaleImageFile(file)
      .then(function (res) {
        // Guard: keep only one image.
        state.compose.imageDataUrl = res && res.dataUrl ? res.dataUrl : null;
        state.compose.imageMime = res && res.mime ? res.mime : "";
        if (!state.compose.imageDataUrl) throw new Error("no_data");
        renderComposeImage();
        showToast("Изображение добавлено.");
      })
      .catch(function (e) {
        console.error("Image paste/resize error", e);
        showToast("Не удалось обработать изображение.");
        clearComposeImage();
      })
      .then(function () {
        state.compose.imageBusy = false;
        renderComposeImage();
      });
  }

  function handlePaste(e) {
    if (!e || !e.clipboardData || !e.clipboardData.items) return;
    if (state && state.access && state.access.mode === "demo") {
      showToast("Изображения доступны после авторизации.");
      return;
    }
    var items = e.clipboardData.items;
    var file = null;
    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      if (!it || it.kind !== "file") continue;
      if (it.type && it.type.indexOf("image/") === 0) {
        file = it.getAsFile ? it.getAsFile() : null;
        break;
      }
    }
    if (!file) return;

    // Replace existing image (only one allowed).
    setComposeImageFromFile(file);
  }
  function syncTtsButtons() {
    var btns = document.querySelectorAll(".tutor-ttsbtn");
    for (var i = 0; i < btns.length; i++) {
      var b = btns[i];
      var ts = parseInt(b.getAttribute("data-ts") || "0", 10) || 0;
      var isLoading = !!(state.tts && state.tts.busy && state.tts.busyTs === ts);
      var isPlaying = !!(state.tts && state.tts.playingTs && state.tts.playingTs === ts);

      b.classList.toggle("is-loading", isLoading);
      b.classList.toggle("is-playing", isPlaying);

      if (isLoading) {
        b.disabled = true;
        b.textContent = "…";
        b.setAttribute("aria-busy", "true");
      } else {
        b.disabled = false;
        b.textContent = "▶";
        b.removeAttribute("aria-busy");
      }
      b.setAttribute("aria-pressed", isPlaying ? "true" : "false");
    }
  }

  function ttsStop() {
    if (!state.tts) return;

    if (state.tts.abortCtrl && typeof state.tts.abortCtrl.abort === "function") {
      try { state.tts.abortCtrl.abort(); } catch (e) {}
    }
    state.tts.abortCtrl = null;

    if (state.tts.audio) {
      try {
        state.tts.audio.pause();
        state.tts.audio.src = "";
      } catch (e) {}
    }
    state.tts.audio = null;

    if (state.tts.url) {
      try { URL.revokeObjectURL(state.tts.url); } catch (e) {}
    }
    state.tts.url = "";

    state.tts.playingTs = null;
    state.tts.busy = false;
    state.tts.busyTs = null;

    syncTtsButtons();
  }

  function ttsPlay(messageTs, text) {
    if (!text) return;

    if (state && state.access && state.access.mode === "demo") {
      showToast("Озвучивание доступно после авторизации.");
      return;
    }

    // Stop currently playing audio or any in-flight request.
    ttsStop();

    if (!state.tts) return;
    state.tts.reqId = (state.tts.reqId || 0) + 1;
    var reqId = state.tts.reqId;

    var controller = window.AbortController ? new AbortController() : null;
    state.tts.abortCtrl = controller;
    state.tts.busy = true;
    state.tts.busyTs = messageTs;

    syncTtsButtons();

    fetch(SPEECH_URL, {
      method: "POST",
      cache: "no-store",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ text: String(text) }),
      signal: controller ? controller.signal : undefined,
    })
      .then(function (res) {
        if (!res) throw new Error("upstream");
        if (res.ok) return res.blob();
        if (res.status === 401) {
          return res
            .json()
            .catch(function () {
              return null;
            })
            .then(function (data) {
              var shaped = { ok: false, status: res.status, data: data };
              if (isAuthRequiredResponse(shaped)) {
                openAuthModal(data && data.error ? data.error.message : null);
              }
              throw new Error("auth_required");
            });
        }
        throw new Error("upstream");
      })
      .then(function (blob) {
        if (!state.tts || state.tts.reqId !== reqId) return;

        state.tts.busy = false;
        state.tts.busyTs = null;

        var url = URL.createObjectURL(blob);
        var audio = new Audio(url);
        try { audio.playbackRate = (state.tts && typeof state.tts.playbackRate === "number") ? state.tts.playbackRate : getTtsRate(); } catch (e) {}

        state.tts.url = url;
        state.tts.audio = audio;
        state.tts.playingTs = messageTs;

        audio.addEventListener("ended", function () {
          if (!state.tts) return;
          if (state.tts.url) {
            try { URL.revokeObjectURL(state.tts.url); } catch (e) {}
          }
          state.tts.url = "";
          state.tts.audio = null;
          state.tts.playingTs = null;
          syncTtsButtons();
        });

        audio.addEventListener("error", function () {
          showToast("Не удалось воспроизвести ответ.");
          ttsStop();
        });

        syncTtsButtons();
        var p = audio.play();
        if (p && typeof p.catch === "function") {
          p.catch(function () {
            showToast("Не удалось воспроизвести ответ.");
            ttsStop();
          });
        }
      })
      .catch(function (e) {
        if (!state.tts || state.tts.reqId !== reqId) return;
        if (e && e.name === "AbortError") return;
        if (e && e.message === "auth_required") return;
        console.error("TTS error", e);
        showToast("Не удалось воспроизвести ответ.");
      })
      .finally(function () {
        if (!state.tts || state.tts.reqId !== reqId) return;
        state.tts.busy = false;
        state.tts.busyTs = null;
        state.tts.abortCtrl = null;
        syncTtsButtons();
      });
  }

  function supportsSpeechInput() {
    return !!(
      navigator.mediaDevices &&
      navigator.mediaDevices.getUserMedia &&
      window.MediaRecorder
    );
  }

  function chooseRecorderMimeType() {
    if (!window.MediaRecorder || typeof MediaRecorder.isTypeSupported !== "function") return "";
    for (var i = 0; i < STT_MIME_CANDIDATES.length; i++) {
      var t = STT_MIME_CANDIDATES[i];
      if (MediaRecorder.isTypeSupported(t)) return t;
    }
    return "";
  }

  function sttSetMicVisual(isRecording) {
    var btn = $("micBtn");
    if (!btn) return;
    if (isRecording) btn.classList.add("is-recording");
    else btn.classList.remove("is-recording");
    btn.setAttribute("aria-pressed", isRecording ? "true" : "false");
  }

  function sttStopStream(stream) {
    if (!stream || !stream.getTracks) return;
    try {
      stream.getTracks().forEach(function (track) {
        try { track.stop(); } catch (e) {}
      });
    } catch (e) {}
  }

  function sttReset() {
    if (!state.stt) return;
    if (state.stt.recorder) {
      try {
        if (state.stt.recorder.state !== "inactive") state.stt.recorder.stop();
      } catch (e) {}
    }
    sttStopStream(state.stt.stream);
    state.stt.mode = "idle";
    state.stt.recorder = null;
    state.stt.stream = null;
    state.stt.chunks = [];
    state.stt.mimeType = "";
    state.stt.busy = false;
    sttSetMicVisual(false);

    var mic = $("micBtn");
    if (mic) mic.disabled = false;
  }

    function sttAbort() {
    if (!state.stt) return;
    state.stt.aborting = true;

    try {
      if (state.stt.recorder && state.stt.recorder.state !== "inactive") state.stt.recorder.stop();
    } catch (e) {}

    sttStopStream(state.stt.stream);
    state.stt.mode = "idle";
    state.stt.recorder = null;
    state.stt.stream = null;
    state.stt.chunks = [];
    state.stt.mimeType = "";
    state.stt.busy = false;
    sttSetMicVisual(false);

    var mic = $("micBtn");
    if (mic) mic.disabled = false;

    voiceHintReady();
  }

  function sttAppendToInput(text) {
    var input = $("chatInput");
    if (!input) return;

    var existing = String(input.value || "");
    var add = String(text || "").trim();
    if (!add) return;

    if (existing.trim().length === 0) {
      input.value = add;
    } else {
      input.value = existing.replace(/\s+$/g, "") + " " + add;
    }

    input.focus();
  }

  function sttTranscribe(blob, mimeType) {
    if (!blob || !blob.size) {
      setVoiceHint("Не удалось распознать речь. Попробуйте ещё раз.", "is-error");
      sttReset();
      return;
    }

    var mic = $("micBtn");
    if (mic) mic.disabled = true;

    setVoiceHint("Распознаём речь…", "is-busy");

    var ext = mimeType && mimeType.indexOf("ogg") >= 0 ? "ogg" : "webm";
    var filename = "speech." + ext;

    var form = new FormData();
    form.append("file", blob, filename);

    fetch(TRANSCRIBE_URL, {
      method: "POST",
      body: form,
      credentials: "same-origin",
    })
      .then(function (res) {
        return res
          .json()
          .catch(function () {
            return null;
          })
          .then(function (data) {
            return { ok: res.ok, status: res.status, data: data };
          });
      })
      .then(function (res) {
        if (isAuthRequiredResponse(res)) {
          openAuthModal(res.data && res.data.error ? res.data.error.message : null);
          throw new Error(res.data && res.data.error && res.data.error.message ? res.data.error.message : 'Требуется код доступа.');
        }

        if (isAuthRequiredResponse(res)) {
          openAuthModal(res.data && res.data.error ? res.data.error.message : null);
          setVoiceHint('Введите код доступа, чтобы пользоваться голосовым вводом.', 'is-error');
          return;
        }

        if (!res || !res.ok || !res.data || typeof res.data !== "object") {
          var msg = "Не удалось распознать речь. Попробуйте ещё раз.";
          console.error("transcribe failed", res);
          setVoiceHint(msg, "is-error");
          return;
        }

        var text = typeof res.data.text === "string" ? res.data.text.trim() : "";
        if (!text) {
          console.error("transcribe empty text", res.data);
          setVoiceHint("Не удалось распознать речь. Попробуйте ещё раз.", "is-error");
          return;
        }

        sttAppendToInput(text);
        setVoiceHint("Речь распознана. Проверьте текст и нажмите Send.", "is-success");
      })
      .catch(function (e) {
        console.error("transcribe error", e);
        setVoiceHint("Не удалось распознать речь. Попробуйте ещё раз.", "is-error");
      })
      .finally(function () {
        sttReset();
        var btn = $("micBtn");
        if (btn) btn.disabled = false;
      });
  }

  function sttStartRecording() {
    if (!supportsSpeechInput()) {
      setVoiceHint("Голосовой ввод не поддерживается этим браузером.", "is-error");
      return;
    }

    if (state.stt.mode !== "idle") return;

    setVoiceHint("Запрашиваем доступ к микрофону…", "is-busy");
    state.stt.busy = true;

    var mic = $("micBtn");
    if (mic) mic.disabled = true;

    navigator.mediaDevices
      .getUserMedia({ audio: true })
      .then(function (stream) {
        state.stt.stream = stream;

        var mimeType = chooseRecorderMimeType();
        state.stt.mimeType = mimeType;

        var rec;
        try {
          rec = mimeType ? new MediaRecorder(stream, { mimeType: mimeType }) : new MediaRecorder(stream);
        } catch (e) {
          sttStopStream(stream);
          state.stt.stream = null;
          setVoiceHint("Голосовой ввод не поддерживается этим браузером.", "is-error");
          return;
        }

        state.stt.recorder = rec;
        state.stt.chunks = [];
        state.stt.mode = "recording";

        rec.addEventListener("dataavailable", function (evt) {
          if (evt && evt.data && evt.data.size > 0) state.stt.chunks.push(evt.data);
        });

        rec.addEventListener("stop", function () {
          var aborting = !!state.stt.aborting;
          state.stt.aborting = false;
          if (aborting) {
            sttStopStream(state.stt.stream);
            state.stt.mode = "idle";
            state.stt.recorder = null;
            state.stt.stream = null;
            state.stt.chunks = [];
            sttSetMicVisual(false);
            return;
          }
          var usedMime = rec.mimeType || state.stt.mimeType || "audio/webm";
          var chunks = state.stt.chunks || [];
          var blob;
          try {
            blob = new Blob(chunks, { type: usedMime });
          } catch (e) {
            blob = new Blob(chunks);
          }

          sttStopStream(state.stt.stream);
          state.stt.stream = null;
          state.stt.recorder = null;
          state.stt.chunks = [];
          state.stt.mode = "transcribing";
          sttSetMicVisual(false);

          sttTranscribe(blob, usedMime);
        });

        sttSetMicVisual(true);
        setVoiceHint("Идёт запись… Нажмите микрофон ещё раз, чтобы остановить.", "is-recording");

        try {
          rec.start();
        } catch (e) {
          sttStopStream(stream);
          state.stt.stream = null;
          state.stt.mode = "idle";
          sttSetMicVisual(false);
          setVoiceHint("Не удалось начать запись. Попробуйте ещё раз.", "is-error");
          return;
        }

        if (mic) mic.disabled = false;
        state.stt.busy = false;
      })
      .catch(function (err) {
        state.stt.busy = false;
        if (mic) mic.disabled = false;

        var name = err && err.name ? String(err.name) : "";
        if (name === "NotAllowedError" || name === "SecurityError") {
          setVoiceHint("Нет доступа к микрофону. Разрешите доступ в настройках браузера.", "is-error");
        } else {
          setVoiceHint("Не удалось получить доступ к микрофону. Попробуйте ещё раз.", "is-error");
        }
      });
  }

  function sttStopRecording() {
    if (state.stt.mode !== "recording") return;

    var mic = $("micBtn");
    if (mic) mic.disabled = true;

    setVoiceHint("Распознаём речь…", "is-busy");
    sttSetMicVisual(false);

    try {
      if (state.stt.recorder && state.stt.recorder.state !== "inactive") state.stt.recorder.stop();
    } catch (e) {
      console.error("recorder.stop failed", e);
      sttReset();
      setVoiceHint("Не удалось остановить запись. Попробуйте ещё раз.", "is-error");
      if (mic) mic.disabled = false;
    }
  }

  function handleMicClick() {
    if (!state.topicId) return;
    if (!state.stt) return;

    if (state.access && state.access.mode === "demo") {
      showToast("Голосовой ввод доступен после авторизации.");
      return;
    }

    if (state.stt.mode === "recording") {
      sttStopRecording();
      return;
    }

    if (state.stt.mode === "idle") {
      sttStartRecording();
      return;
    }

    // transcribing/busy -> ignore
  }
  function renderLevelPicker(selectedLevel) {
    var wrap = $("levelPicker");
    wrap.innerHTML = "";

    LEVELS.forEach(function (lvl) {
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "tutor-btn tutor-btn--ghost " + (lvl === selectedLevel ? "tutor-btn--active" : "");
      btn.textContent = lvl;
      btn.addEventListener("click", function () {
        setSelectedLevel(lvl);
        state.level = lvl;
        renderLevelPicker(lvl);
      });
      wrap.appendChild(btn);
    });
  }

  function renderTopics() {
    var grid = $("topicGrid");
    grid.innerHTML = "";

    TOPICS.forEach(function (t) {
      var card = document.createElement("div");
      card.className = "tutor-card";
      card.tabIndex = 0;
      card.setAttribute("role", "button");

      var title = document.createElement("div");
      title.className = "tutor-card__title";
      title.textContent = t.emoji + " " + (t.cardTitle || t.title);

      var desc = document.createElement("p");
      desc.className = "tutor-card__desc";
      desc.textContent = t.desc || t.subtitle;

      card.appendChild(title);
      card.appendChild(desc);

      function open() {
        openChat(t.id);
      }

      card.addEventListener("click", open);
      card.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          open();
        }
      });

      grid.appendChild(card);
    });
  }

  function setView(name) {
    state.view = name;
    $("view-home").hidden = name !== "home";
    $("view-chat").hidden = name !== "chat";
    applyNavUi();
  }

  function findTopic(topicId) {
    for (var i = 0; i < TOPICS.length; i++) {
      if (TOPICS[i].id === topicId) return TOPICS[i];
    }
    return null;
  }

  function updateChatMeta() {
    var topic = findTopic(state.topicId);
    $("chatTitle").textContent = topic ? topic.emoji + " " + topic.title + " — " + topic.subtitle : "";
    $("chatLevel").textContent = state.level;
  }

  function renderMessages(messages) {
    var wrap = $("chatMessages");
    wrap.innerHTML = "";

    messages.forEach(function (m) {
      var item = document.createElement("div");
      var isUser = m.role === "user";
      item.className =
        "tutor-msg " +
        (isUser ? "tutor-msg--user" : "tutor-msg--ai") +
        (m.pending ? " tutor-msg--pending" : "") +
        (m.error ? " tutor-msg--error" : "");

      var head = document.createElement("div");
      head.className = "tutor-msg__head";

      var role = document.createElement("div");
      role.className = "tutor-msg__role";
      role.textContent = isUser ? "You" : (m.pending ? "AI…" : "AI Tutor");

      head.appendChild(role);

      if (!isUser && !m.pending && !m.error && m.text) {
        var tools = document.createElement("div");
        tools.className = "tutor-msg__tools";

        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "tutor-ttsbtn";
        btn.textContent = "▶";
        btn.setAttribute("title", "Прослушать ответ");
        btn.setAttribute("aria-label", "Прослушать ответ");
        btn.setAttribute("data-ts", String(m.ts || 0));

        btn.addEventListener("click", function (e) {
          e.preventDefault();
          ttsPlay(m.ts || 0, m.text || "");
        });

        tools.appendChild(btn);
        head.appendChild(tools);
      }

      var text = document.createElement("div");
      text.className = "tutor-msg__text";
      var displayText = m.text || "";
      if (isUser && displayText === "[Image]") displayText = "📷 Изображение";
      text.textContent = displayText;

      item.appendChild(head);
      item.appendChild(text);
      wrap.appendChild(item);
    });

    wrap.scrollTop = wrap.scrollHeight;
    syncTtsButtons();
  }

  function setToggleActive(groupIds, activeId) {
    groupIds.forEach(function (id) {
      var el = $(id);
      if (!el) return;
      if (id === activeId) el.classList.add("is-active");
      else el.classList.remove("is-active");
    });
  }

  function renderAnalysisEmpty() {
    var content = $("analysisContent");
    content.innerHTML =
      '<div class="tutor-empty">После вашего ответа здесь появятся исправления и рекомендации преподавателя.</div>';
  }

  function renderAnalysisLoading() {
    var content = $("analysisContent");
    content.innerHTML =
      '<div class="tutor-empty tutor-loading"><span class="tutor-spinner" aria-hidden="true"></span>AI анализирует сообщение…</div>';
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function renderAnalysisError(message) {
    var content = $("analysisContent");
    content.innerHTML =
      '<div class="tutor-empty tutor-error">' +
      escapeHtml(message || "Не удалось получить разбор. Попробуйте ещё раз.") +
      "</div>";
  }

  function renderAnalysis(analysis) {
    var content = $("analysisContent");
    if (!analysis || typeof analysis !== "object") {
      renderAnalysisEmpty();
      return;
    }

    var uiLang = getAnalysisLang();
    var t = {
      okDefault: uiLang === "en" ? "Excellent! No mistakes found." : "Отлично! Ошибок не найдено.",
      altTitle: uiLang === "en" ? "A more natural option" : "Вариант звучит естественнее",
      explLabel: uiLang === "en" ? "Explanation" : "Объяснение",
      exLabel: uiLang === "en" ? "Example" : "Пример",
    };

    var hasErrors = !!analysis.hasErrors;
    var positiveFeedback = typeof analysis.positiveFeedback === "string" ? analysis.positiveFeedback.trim() : "";
    var naturalAlternative =
      typeof analysis.naturalAlternative === "string" ? analysis.naturalAlternative.trim() : "";
    var issues = Array.isArray(analysis.issues) ? analysis.issues : [];

    var html = "";

    if (!hasErrors) {
      html +=
        '<div class="tutor-ok">' +
        "✅ " +
        escapeHtml(positiveFeedback || t.okDefault) +
        "</div>";

      if (naturalAlternative) {
        html +=
          '<div class="tutor-note"><div class="tutor-note__title">' +
          escapeHtml(t.altTitle) +
          "</div>" +
          '<div class="tutor-note__body">' +
          escapeHtml(naturalAlternative) +
          "</div></div>";
      }

      content.innerHTML = html;
      return;
    }

    if (positiveFeedback) {
      html += '<div class="tutor-ok">' + "✅ " + escapeHtml(positiveFeedback) + "</div>";
    }

    if (naturalAlternative) {
      html +=
        '<div class="tutor-note"><div class="tutor-note__title">' +
        escapeHtml(t.altTitle) +
        "</div>" +
        '<div class="tutor-note__body">' +
        escapeHtml(naturalAlternative) +
        "</div></div>";
    }

    if (!issues.length) {
      html += '<div class="tutor-empty">Есть ошибки, но разбор не сформировался. Попробуйте ещё раз.</div>';
      content.innerHTML = html;
      return;
    }

    issues.forEach(function (it) {
      var type = typeof it.type === "string" ? it.type.trim() : "";
      var original = typeof it.original === "string" ? it.original.trim() : "";
      var corrected = typeof it.corrected === "string" ? it.corrected.trim() : "";
      var explanation = typeof it.explanation === "string" ? it.explanation.trim() : "";
      var example = typeof it.example === "string" ? it.example.trim() : "";

      if (!type || !original || !corrected || !explanation || !example) return;

      html +=
        '<div class="tutor-issue">' +
        '<div class="tutor-issue__type">' +
        escapeHtml(type) +
        "</div>" +
        '<div class="tutor-issue__row tutor-wrong">❌ ' +
        escapeHtml(original) +
        "</div>" +
        '<div class="tutor-issue__row tutor-correct">✅ ' +
        escapeHtml(corrected) +
        "</div>" +
        '<div class="tutor-issue__block"><div class="tutor-issue__label">' +
        escapeHtml(t.explLabel) +
        '</div><div class="tutor-issue__text">' +
        escapeHtml(explanation) +
        "</div></div>" +
        '<div class="tutor-issue__block"><div class="tutor-issue__label">' +
        escapeHtml(t.exLabel) +
        '</div><div class="tutor-issue__text">' +
        escapeHtml(example) +
        "</div></div>" +
        "</div>";
    });

    content.innerHTML = html || '<div class="tutor-empty">Разбор не сформировался. Попробуйте ещё раз.</div>';
  }

  function setBusy(isBusy) {
    state.busy = !!isBusy;
    $("sendBtn").disabled = state.busy || demoExhausted();
    $("chatInput").disabled = state.busy;
    $("restartBtn").disabled = state.busy;
    $("backBtn").disabled = state.busy;
    if (!state.busy) $("chatInput").focus();
    applyAccessUi();
  }

  function toApiHistory(messages) {
    var out = [];
    for (var i = 0; i < messages.length; i++) {
      var m = messages[i];
      if (!m) continue;
      if (m.role !== "user" && m.role !== "assistant") continue;
      var text = typeof m.text === "string" ? m.text.trim() : "";
      if (!text) continue;
      out.push({ role: m.role, content: text });
    }
    // Keep last N
    if (out.length > 24) out = out.slice(out.length - 24);
    return out;
  }

  function callTutorApi(payload) {
    var controller = typeof AbortController !== "undefined" ? new AbortController() : null;
    var t = setTimeout(function () {
      if (controller) controller.abort();
    }, 22000);

    return fetch(API_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      signal: controller ? controller.signal : undefined,
      credentials: "same-origin",
    })
      .then(function (res) {
        return res
          .json()
          .catch(function () {
            return null;
          })
          .then(function (data) {
            return { ok: res.ok, status: res.status, data: data };
          });
      })
      .finally(function () {
        clearTimeout(t);
      });
  }

  function openChat(topicId) {
    var topic = findTopic(topicId);
    if (!topic) return;

    state.topicId = topicId;
    state.level = getSelectedLevel();

    var messages = ensureStarter(topic, loadHistory(state.topicId, state.level));
    saveHistory(topicId, state.level, messages);

    updateChatMeta();
    renderMessages(messages);
    renderAnalysisEmpty();
    state.lastAnalysis = null;
    setView("chat");

    voiceHintReady();

    location.hash = "topic=" + encodeURIComponent(topicId);

    setTimeout(function () {
      $("chatInput").focus();
    }, 50);
  }

  function closeChat() {
    if (state.busy) return;
    if (state.stt && state.stt.mode !== "idle") sttAbort();
    ttsStop();
    clearComposeImage();
    state.topicId = null;
    setView("home");
    location.hash = "";
  }

  function sendMessage() {
    if (state.busy) return;
    if (!state.topicId) return;
    var topic = findTopic(state.topicId);
    if (!topic) return;

    if (demoExhausted()) {
      showToast("Демо-лимит исчерпан. Введите код доступа, чтобы продолжить.");
      applyAccessUi();
      setTimeout(function () {
        openAuthModal("Демо-лимит исчерпан. Введите персональный код доступа, чтобы продолжить.");
      }, 0);
      return;
    }

    var input = $("chatInput");
    var text = (input.value || "").trim();
    var hasImage = !!(state.compose && state.compose.imageDataUrl);
    if (state && state.access && state.access.mode === "demo" && hasImage) {
      // Demo is text-only (Stage 6.2C)
      showToast("Изображения доступны после авторизации.");
      clearComposeImage();
      hasImage = false;
    }
    var sendText = text;
    if (!sendText && hasImage) sendText = "[Image]";
    if (!sendText) return;

    var level = getSelectedLevel();
    state.level = level;

    var baseMessages = ensureStarter(topic, loadHistory(state.topicId, level));
    updateChatMeta();

    var pendingUser = { role: "user", text: sendText, ts: Date.now(), pending: true };
    var pendingAi = { role: "assistant", text: "AI отвечает…", ts: Date.now(), pending: true };
    renderMessages(baseMessages.concat([pendingUser, pendingAi]));
    renderAnalysisLoading();
    setBusy(true);

    var payload = {
      topic: state.topicId,
      level: level,
      analysisLanguage: getAnalysisLang(),
      strictness: getStrictness(),
      history: toApiHistory(baseMessages.concat([{ role: "user", text: sendText, ts: Date.now() }])),
      imageDataUrl: hasImage ? state.compose.imageDataUrl : null,
    };

    callTutorApi(payload)
      .then(function (res) {
        if (isAuthRequiredResponse(res)) {
          setTimeout(function () {
            openAuthModal(res.data && res.data.error ? res.data.error.message : null);
          }, 0);
          throw new Error("auth_required");
        }

        if (!res || !res.ok || !res.data || typeof res.data !== "object") {
          if (res && res.data && res.data.error && res.data.error.code === "demo_limit") {
            // Demo mode exhausted on server side.
            setAccess("demo", (state && state.access ? state.access.demoId : ""), 0);
          }
          var msg =
            res && res.data && res.data.error && typeof res.data.error.message === "string"
              ? res.data.error.message
              : "Не удалось получить ответ от AI. Попробуйте ещё раз.";
          throw new Error(msg);
        }

        var reply = typeof res.data.reply === "string" ? res.data.reply.trim() : "";
        var analysis = res.data.analysis || null;

        var next = baseMessages.slice();
        next.push({ role: "user", text: sendText, ts: Date.now() });
        next.push({ role: "assistant", text: reply || "Sorry — could you say that again?", ts: Date.now() });
        saveHistory(state.topicId, level, next);
        renderMessages(next);

        state.lastAnalysis = analysis;
        renderAnalysis(analysis);

        clearComposeImage();
        input.value = "";

        if (state && state.access && state.access.mode === "demo") {
          state.access.remaining = Math.max(0, (state.access.remaining | 0) - 1);
          applyAccessUi();
          if ((state.access.remaining | 0) <= 0) {
            // Demo exhausted: let the visitor switch to full Tutor by entering a code.
            setTimeout(function () {
              openAuthModal("Демо-лимит исчерпан. Введите персональный код доступа, чтобы продолжить.");
            }, 0);
          }
        }
      })
      .catch(function (e) {
        if (e && e.message === "auth_required") {
          renderMessages(baseMessages);
          renderAnalysisEmpty();
          input.value = text;
          return;
        }
        var msg = e && e.message ? e.message : "Не удалось получить ответ от AI. Попробуйте ещё раз.";
        showToast(msg);
        renderMessages(baseMessages);
        renderAnalysisError(msg);
        input.value = text;
      })
      .finally(function () {
        setBusy(false);
      });
  }

  function restartScenario() {
    if (state.busy) return;
    if (state.stt && state.stt.mode !== "idle") sttAbort();
    ttsStop();
    clearComposeImage();
    if (!state.topicId) return;
    var topic = findTopic(state.topicId);
    if (!topic) return;

    var ok = window.confirm("Начать сначала? История текущей темы и уровня будет очищена.");
    if (!ok) return;

    var level = getSelectedLevel();
    var fresh = ensureStarter(topic, []);
    saveHistory(state.topicId, level, fresh);
    renderMessages(fresh);
    renderAnalysisEmpty();
    state.lastAnalysis = null;
    $("chatInput").value = "";
    $("chatInput").focus();
    voiceHintReady();
  }

  function applyControlState() {
    var lang = getAnalysisLang();
    setToggleActive(["langRu", "langEn"], lang === "ru" ? "langRu" : "langEn");

    var strict = getStrictness();
    setToggleActive(
      ["strictFriendly", "strictNormal", "strictStrict"],
      strict === "friendly" ? "strictFriendly" : strict === "strict" ? "strictStrict" : "strictNormal"
    );
  }

  function bindControls() {
    $("backBtn").addEventListener("click", closeChat);
    $("restartBtn").addEventListener("click", restartScenario);

    $("sendBtn").addEventListener("click", sendMessage);
    $("chatInput").addEventListener("keydown", function (e) {
      if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });

    $("chatInput").addEventListener("paste", handlePaste);

    $("micBtn").addEventListener("click", handleMicClick);

    $("langRu").addEventListener("click", function () {
      setAnalysisLang("ru");
      applyControlState();
    });
    $("langEn").addEventListener("click", function () {
      setAnalysisLang("en");
      applyControlState();
    });

    $("strictFriendly").addEventListener("click", function () {
      setStrictness("friendly");
      applyControlState();
    });
    $("strictNormal").addEventListener("click", function () {
      setStrictness("normal");
      applyControlState();
    });
    $("strictStrict").addEventListener("click", function () {
      setStrictness("strict");
      applyControlState();
    });
  }

  function bootFromHash() {
    var h = (location.hash || "").replace(/^#/, "");
    if (!h) return;
    var m = h.match(/topic=([^&]+)/);
    if (!m) return;
    var topicId = decodeURIComponent(m[1]);
    if (findTopic(topicId)) openChat(topicId);
  }

  var state = {
    view: "home",
    level: getSelectedLevel(),
    topicId: null,
    busy: false,
    access: { mode: "guest", demoId: "", remaining: 0 },
    lastAnalysis: null,
    stt: { mode: "idle", recorder: null, stream: null, chunks: [], mimeType: "", busy: false, aborting: false },
    tts: { reqId: 0, audio: null, url: "", playingTs: null, busy: false, busyTs: null, playbackRate: getTtsRate(), abortCtrl: null },
    compose: { imageDataUrl: null, imageMime: "", imageBusy: false },
  };

  function init() {
    applyStandaloneMode();
    renderLevelPicker(getSelectedLevel());
    renderTopics();
    applyControlState();
    bindControls();
    ensureTtsDisclosure();
    ensureTtsRateControl();
    syncAccessFromServer().finally(function () {
      bootFromHash();
      renderAnalysisEmpty();
      applyAccessUi();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();























