(function () {
  "use strict";

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

  var DEMO_AI_REPLY =
    "Thank you! This is a demo reply. The AI conversation\nwill be connected at the next stage.";

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

  function loadHistory(topicId, level) {
    var raw = localStorage.getItem(historyKey(topicId, level));
    var data = safeJsonParse(raw, []);
    return Array.isArray(data) ? data : [];
  }

  function saveHistory(topicId, level, messages) {
    localStorage.setItem(historyKey(topicId, level), JSON.stringify(messages));
  }

  function ensureStarter(topic, messages) {
    if (!messages || messages.length === 0) {
      return [
        {
          role: "ai",
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
      btn.className = "tutor-btn tutor-btn--ghost";
      if (lvl === selectedLevel) btn.className += " tutor-btn--active";
      btn.textContent = lvl;
      btn.addEventListener("click", function () {
        setSelectedLevel(lvl);
        state.level = lvl;
        renderLevelPicker(lvl);
        if (state.view === "chat") updateChatMeta();
      });
      wrap.appendChild(btn);
    });
  }

  function renderTopics() {
    var grid = $("topicGrid");
    grid.innerHTML = "";

    TOPICS.forEach(function (topic) {
      var card = document.createElement("div");
      card.className = "tutor-card";
      card.tabIndex = 0;
      card.setAttribute("role", "button");
      card.setAttribute("aria-label", topic.title + ". " + topic.subtitle);

      var title = document.createElement("div");
      title.className = "tutor-card__title";
      title.textContent = topic.emoji + " " + topic.title;

      var desc = document.createElement("p");
      desc.className = "tutor-card__desc";
      desc.textContent = topic.subtitle;

      card.appendChild(title);
      card.appendChild(desc);

      function open() {
        openChat(topic.id);
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
      item.className = "tutor-msg " + (m.role === "user" ? "tutor-msg--user" : "tutor-msg--ai");

      var role = document.createElement("div");
      role.className = "tutor-msg__role";
      role.textContent = m.role === "user" ? "You" : "AI Tutor";

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

  function renderAnalysis(messages) {
    var content = $("analysisContent");
    var lang = getAnalysisLang();
    var strict = getStrictness();

    if (!hasUserMessage(messages)) {
      content.innerHTML =
        '<div class="tutor-empty">После вашего ответа здесь появятся исправления и рекомендации преподавателя.</div>';
      return;
    }

    var toneRu =
      strict === "friendly"
        ? "Мягко и спокойно"
        : strict === "strict"
        ? "Строго и по делу"
        : "Нормально";

    var toneEn =
      strict === "friendly"
        ? "Friendly"
        : strict === "strict"
        ? "Strict"
        : "Normal";

    var header = lang === "ru" ? "Демонстрация интерфейса (не реальный разбор)" : "Demo UI (not a real analysis)";
    var tone = lang === "ru" ? ("Режим: " + toneRu) : ("Mode: " + toneEn);

    var explRu =
      strict === "strict"
        ? "После agree не нужен am."
        : "После глагола agree не используется am.";

    var explEn =
      strict === "strict"
        ? "Do not use am after agree."
        : "We don’t use am after the verb agree.";

    var exampleTitle = lang === "ru" ? "Пример" : "Example";
    var explanationTitle = lang === "ru" ? "Объяснение" : "Explanation";

    var userText = lastUserText(messages).trim();
    var userLine =
      userText.length > 0
        ? (lang === "ru" ? ("Ваше сообщение: “" + userText.slice(0, 80) + (userText.length > 80 ? "…" : "") + "”") : ("Your message: “" + userText.slice(0, 80) + (userText.length > 80 ? "…" : "") + "”"))
        : "";

    content.innerHTML =
      '<div class="tutor-empty">' +
      header +
      "<br/>" +
      tone +
      (userLine ? "<br/>" + userLine : "") +
      "</div>" +
      '<div class="tutor-kv">' +
      '<div class="tutor-kv__label">Grammar</div>' +
      '<div class="tutor-wrong">✖ I am agree.</div>' +
      '<div class="tutor-correct">✔ I agree.</div>' +
      "<div style=\"margin-top:10px;\">" +
      "<b>" +
      explanationTitle +
      ":</b> " +
      (lang === "ru" ? explRu : explEn) +
      "</div>" +
      "<div style=\"margin-top:10px;\"><b>" +
      exampleTitle +
      ":</b> I agree with you.</div>" +
      "</div>";
  }

  function openChat(topicId) {
    var topic = findTopic(topicId);
    if (!topic) return;

    state.topicId = topicId;
    state.level = getSelectedLevel();

    var messages = ensureStarter(topic, loadHistory(topicId, state.level));
    saveHistory(topicId, state.level, messages);

    updateChatMeta();
    renderMessages(messages);
    renderAnalysis(messages);
    setView("chat");

    location.hash = "topic=" + encodeURIComponent(topicId);

    setTimeout(function () {
      $("chatInput").focus();
    }, 50);
  }

  function closeChat() {
    state.topicId = null;
    setView("home");
    location.hash = "";
  }

  function sendMessage() {
    if (!state.topicId) return;
    var topic = findTopic(state.topicId);
    if (!topic) return;

    var input = $("chatInput");
    var text = (input.value || "").trim();
    if (!text) return;

    var level = getSelectedLevel();
    state.level = level;

    var messages = loadHistory(state.topicId, level);
    messages = ensureStarter(topic, messages);
    messages.push({ role: "user", text: text, ts: Date.now() });
    saveHistory(state.topicId, level, messages);
    renderMessages(messages);
    input.value = "";

    setTimeout(function () {
      var again = loadHistory(state.topicId, level);
      again.push({ role: "ai", text: DEMO_AI_REPLY, ts: Date.now() });
      saveHistory(state.topicId, level, again);
      renderMessages(again);
      renderAnalysis(again);
    }, 420);
  }

  function restartScenario() {
    if (!state.topicId) return;
    var topic = findTopic(state.topicId);
    if (!topic) return;

    var ok = window.confirm("Начать сначала? История текущей темы и уровня будет очищена.");
    if (!ok) return;

    var level = getSelectedLevel();
    var fresh = ensureStarter(topic, []);
    saveHistory(state.topicId, level, fresh);
    renderMessages(fresh);
    renderAnalysis(fresh);
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
      if (state.view === "chat" && state.topicId) {
        renderAnalysis(loadHistory(state.topicId, getSelectedLevel()));
      }
    });
    $("langEn").addEventListener("click", function () {
      setAnalysisLang("en");
      applyControlState();
      if (state.view === "chat" && state.topicId) {
        renderAnalysis(loadHistory(state.topicId, getSelectedLevel()));
      }
    });

    $("strictFriendly").addEventListener("click", function () {
      setStrictness("friendly");
      applyControlState();
      if (state.view === "chat" && state.topicId) {
        renderAnalysis(loadHistory(state.topicId, getSelectedLevel()));
      }
    });
    $("strictNormal").addEventListener("click", function () {
      setStrictness("normal");
      applyControlState();
      if (state.view === "chat" && state.topicId) {
        renderAnalysis(loadHistory(state.topicId, getSelectedLevel()));
      }
    });
    $("strictStrict").addEventListener("click", function () {
      setStrictness("strict");
      applyControlState();
      if (state.view === "chat" && state.topicId) {
        renderAnalysis(loadHistory(state.topicId, getSelectedLevel()));
      }
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
  };

  function init() {
    renderLevelPicker(getSelectedLevel());
    renderTopics();
    applyControlState();
    bindControls();
    bootFromHash();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
