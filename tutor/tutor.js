(function () {
  "use strict";

  var API_URL = "/api/chat.php";

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
  ];

  var LS = {
    level: "tutor_level",
    lang: "tutor_analysis_lang",
    strict: "tutor_analysis_strictness",
  };

  function $(id) {
    return document.getElementById(id);
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

  function historyKey(topicId, level) {
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
      title.textContent = t.emoji + " " + t.title;

      var desc = document.createElement("p");
      desc.className = "tutor-card__desc";
      desc.textContent = t.subtitle;

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

      var role = document.createElement("div");
      role.className = "tutor-msg__role";
      role.textContent = isUser ? "You" : (m.pending ? "AI…" : "AI Tutor");

      var text = document.createElement("div");
      text.className = "tutor-msg__text";
      text.textContent = m.text || "";

      item.appendChild(role);
      item.appendChild(text);
      wrap.appendChild(item);
    });

    wrap.scrollTop = wrap.scrollHeight;
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
    $("sendBtn").disabled = state.busy;
    $("chatInput").disabled = state.busy;
    $("restartBtn").disabled = state.busy;
    $("backBtn").disabled = state.busy;
    if (!state.busy) $("chatInput").focus();
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

    location.hash = "topic=" + encodeURIComponent(topicId);

    setTimeout(function () {
      $("chatInput").focus();
    }, 50);
  }

  function closeChat() {
    if (state.busy) return;
    state.topicId = null;
    setView("home");
    location.hash = "";
  }

  function sendMessage() {
    if (state.busy) return;
    if (!state.topicId) return;
    var topic = findTopic(state.topicId);
    if (!topic) return;

    var input = $("chatInput");
    var text = (input.value || "").trim();
    if (!text) return;

    var level = getSelectedLevel();
    state.level = level;

    var baseMessages = ensureStarter(topic, loadHistory(state.topicId, level));
    updateChatMeta();

    var pendingUser = { role: "user", text: text, ts: Date.now(), pending: true };
    var pendingAi = { role: "assistant", text: "AI отвечает…", ts: Date.now(), pending: true };
    renderMessages(baseMessages.concat([pendingUser, pendingAi]));
    renderAnalysisLoading();
    setBusy(true);

    var payload = {
      topic: state.topicId,
      level: level,
      analysisLanguage: getAnalysisLang(),
      strictness: getStrictness(),
      history: toApiHistory(baseMessages.concat([{ role: "user", text: text, ts: Date.now() }])),
    };

    callTutorApi(payload)
      .then(function (res) {
        if (!res || !res.ok || !res.data || typeof res.data !== "object") {
          var msg =
            res && res.data && res.data.error && typeof res.data.error.message === "string"
              ? res.data.error.message
              : "Не удалось получить ответ от AI. Попробуйте ещё раз.";
          throw new Error(msg);
        }

        var reply = typeof res.data.reply === "string" ? res.data.reply.trim() : "";
        var analysis = res.data.analysis || null;

        var next = baseMessages.slice();
        next.push({ role: "user", text: text, ts: Date.now() });
        next.push({ role: "assistant", text: reply || "Sorry — could you say that again?", ts: Date.now() });
        saveHistory(state.topicId, level, next);
        renderMessages(next);

        state.lastAnalysis = analysis;
        renderAnalysis(analysis);

        input.value = "";
      })
      .catch(function (e) {
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

    $("micBtn").addEventListener("click", function () {
      showToast("Голосовой ввод будет подключён на следующем этапе.");
    });

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
    lastAnalysis: null,
  };

  function init() {
    renderLevelPicker(getSelectedLevel());
    renderTopics();
    applyControlState();
    bindControls();
    bootFromHash();
    renderAnalysisEmpty();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
