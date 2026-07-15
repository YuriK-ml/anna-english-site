<?php
// index.php для korobkovhub.ru / anna.korobkovhub.ru

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. get_user

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_user'])) {

    $name = '';
    $contact = '';

    if (isset($_POST['name'])) {
        $name = trim($_POST['name']);
    }

    if (isset($_POST['contact'])) {
        $contact = trim($_POST['contact']);
    }

    $file = __DIR__ . '/users.json';

    if (!file_exists($file)) {
        file_put_contents($file, '[]');
    }

    $json = file_get_contents($file);
    $users = json_decode($json, true);

    if (!is_array($users)) {
        $users = array();
    }

    $test_user = null;

    foreach ($users as $u) {
        if (
            ($name !== '' && isset($u['name']) && $u['name'] === $name) ||
            ($contact !== '' && isset($u['contact']) && $u['contact'] === $contact)
        ) {
            $test_user = $u['test_user'];
            break;
        }
    }

    if ($test_user === null) {

        $all_test_users = array("Test1","Test2","Test3","Test4","Test5","Test6","Test7");

        $used = array();
        foreach ($users as $u) {
            if (isset($u['test_user'])) {
                $used[] = $u['test_user'];
            }
        }

        $free = array_diff($all_test_users, $used);

        if (count($free) > 0) {
            $free_values = array_values($free);
            $test_user = $free_values[0];
        } else {
            usort($users, function($a, $b) {
                $t1 = isset($a['last_used']) ? strtotime($a['last_used']) : 0;
                $t2 = isset($b['last_used']) ? strtotime($b['last_used']) : 0;
                return $t1 - $t2;
            });
            $test_user = $users[0]['test_user'];
        }

        $users[] = array(
            'name' => $name,
            'contact' => $contact,
            'test_user' => $test_user,
            'last_used' => date('Y-m-d H:i:s')
        );
    }

    for ($i = 0; $i < count($users); $i++) {
        if ($users[$i]['test_user'] === $test_user) {
            $users[$i]['last_used'] = date('Y-m-d H:i:s');
        }
    }

    file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX);

    echo $test_user;
    exit;
}

// --- Telegram ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {

    $token = $TELEGRAM_TOKEN;
    $chat_ids = [1387433465, 8527953030];

    $message = $_POST['message'];

    foreach ($chat_ids as $chat_id) {
        file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
            "chat_id" => $chat_id,
            "text" => $message
        ]));
    }

    echo "ok";
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Anna English AI — обучение английскому с AI | тест и подбор программы</title>
    <meta name="description" content="Anna English AI – умный AI-помощник и образовательные ресурсы для изучения английского. Курсы, тесты и AI-приложения для эффективного обучения.">
    <meta name="keywords" content="English, AI, AI Assistant, Learning, Anna, Education, Test, Courses">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg: rgba(0,0,0,0.58);
            --bg-2: rgba(0,0,0,0.70);
            --border: rgba(255,255,255,0.08);
            --text-muted: rgba(255,255,255,0.78);
            --accent: #3498db;
            --accent-2: #2c80b4;
        }

        body {
            font-family: system-ui, -apple-system, Segoe UI, Arial, sans-serif;
            text-align: center;
            margin: 0;
            padding: 0;
            background-image: url('images/London.webp');
            background-repeat: no-repeat;
            background-position: center center;
            background-attachment: fixed;
            background-size: cover;
            background-color: #070a0f;
            color: #fff;
        }

        h1 { text-shadow: 1px 1px 4px #000; }
        p { font-size: 18px; text-shadow: 1px 1px 3px #000; }

        .page {
            max-width: 1040px;
            margin: 0 auto;
            padding: 28px 16px 64px;
        }

        .section {
            margin: 22px 0;
            background-color: var(--bg);
            padding: 22px;
            border-radius: 12px;
            border: 1px solid var(--border);
            box-shadow: 0 10px 40px rgba(0,0,0,0.35);
        }

        .cards {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }

        .card {
            width: 260px;
            background-color: rgba(0,0,0,0.7);
            padding: 20px;
            border-radius: 12px;
            transition: 0.3s;
        }

        .card:hover { transform: translateY(-5px); }

        .button {
            display: inline-block;
            padding: 12px 20px;
            margin-top: 10px;
            font-size: 15px;
            color: #fff;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            text-decoration: none;
            border-radius: 8px;
            transition: 0.3s;
            cursor: pointer;
        }

        .button:hover {
            transform: translateY(-1px);
            filter: brightness(1.05);
        }

        .card p { font-size: 14px; margin: 10px 0; }

        /* ===== New blocks (Hero + content) ===== */
        .hero {
            text-align: left;
            padding: 56px 16px 18px;
            background: linear-gradient(180deg, rgba(0,0,0,0.70), rgba(0,0,0,0.15));
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .hero-inner {
            max-width: 1040px;
            margin: 0 auto;
            background: rgba(0,0,0,0.28);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 16px;
            padding: 28px 22px;
            box-shadow: 0 12px 50px rgba(0,0,0,0.45);
            backdrop-filter: blur(6px);
        }

        .hero-title {
            margin: 0 0 10px;
            font-size: 38px;
            line-height: 1.12;
            letter-spacing: -0.02em;
            text-shadow: 0 6px 22px rgba(0,0,0,0.75);
        }

        .hero-subtitle {
            margin: 0 0 18px;
            color: var(--text-muted);
            font-size: 18px;
            line-height: 1.5;
            text-shadow: 0 6px 18px rgba(0,0,0,0.7);
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .button-ghost {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.14);
        }

        .lead {
            color: var(--text-muted);
            margin: 10px 0 0;
            font-size: 14px;
            text-shadow: none;
        }

        .feature-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            margin-top: 14px;
        }

        .mini-card {
            text-align: left;
            background: var(--bg-2);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            padding: 16px;
        }

        .mini-card h3 {
            margin: 0 0 8px;
            font-size: 16px;
        }

        .mini-card p {
            margin: 0;
            font-size: 14px;
            color: var(--text-muted);
            text-shadow: none;
        }

        .list {
            text-align: left;
            margin: 14px auto 0;
            max-width: 760px;
            padding-left: 18px;
            color: var(--text-muted);
        }

        .list li { margin: 8px 0; }

        .steps {
            text-align: left;
            margin: 14px auto 0;
            max-width: 760px;
            padding-left: 18px;
            color: var(--text-muted);
        }

        .steps li { margin: 10px 0; }

        .contact-cta {
            background: linear-gradient(135deg, rgba(52,152,219,0.18), rgba(0,0,0,0.55));
            border-color: rgba(52,152,219,0.25);
        }

        .contact-cta h2 { margin-top: 0; }

        .muted-footer {
            background: rgba(0,0,0,0.35);
            border: 1px solid rgba(255,255,255,0.06);
            opacity: 0.85;
        }

        .muted-footer p {
            font-size: 14px;
            color: var(--text-muted);
            text-shadow: none;
            margin: 8px 0 0;
        }

        @media (max-width: 720px) {
            .hero { padding: 44px 12px 14px; }
            .hero-inner { padding: 22px 16px; }
            .hero-title { font-size: 30px; }
            .hero-subtitle { font-size: 16px; }
            .section { padding: 18px; }
            .feature-grid { grid-template-columns: 1fr; }
            .card { width: 100%; max-width: 360px; }
        }
         
        /* ===== MODAL DARK UI ===== */

        .modal-box {
            background: linear-gradient(145deg, #1e1e1e, #111);
            padding: 30px;
            border-radius: 16px;
            width: 320px;
            margin: 80px auto;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.8);
            border: 1px solid rgba(255,255,255,0.05);
        }
        
        .modal-box h3 {
            margin-bottom: 15px;
            font-size: 20px;
            color: #fff;
        }
        
        .modal-input {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border-radius: 8px;
            border: 1px solid #333;
            background: #111;
            color: #fff;
            font-size: 14px;
            outline: none;
            transition: 0.2s;
        }
        
        .modal-input::placeholder {
            color: #777;
        }
        
        .modal-input:focus {
            border-color: #3498db;
            box-shadow: 0 0 8px rgba(52,152,219,0.5);
        }
        
        .modal-button {
            width: 100%;
            padding: 12px;
            margin-top: 15px;
            font-size: 15px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, #3498db, #2c80b4);
            color: #fff;
            font-weight: bold;
            transition: 0.25s;
        }
        
        .modal-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(52,152,219,0.4);
        }
        
        .modal-back {
            margin-top: 15px;
            font-size: 13px;
            color: #aaa;
            cursor: pointer;
            background: none;
            border: none;
        }
        
        .modal-back:hover {
            color: #fff;
        }
        
        #testResult {
            margin-top: 15px;
            background: rgba(255,255,255,0.05);
            padding: 10px;
            border-radius: 8px;
            font-size: 14px;
        }
          
          
            /* 👇 ВСТАВЬ СЮДА */
        a {
            color: #66ccff;
            font-weight: 500;
            text-decoration: underline;
        }
    
        a:hover {
            color: #99e0ff;
        }      
            
        
    </style>
</head>

<body>

<header class="hero">
    <div class="hero-inner">
        <h1 class="hero-title">Английский для школьников онлайн</h1>
        <p class="hero-subtitle">Индивидуальные занятия с преподавателем Анной на современной платформе</p>
        <div class="hero-actions">
            <a class="button" onclick="openTestModal()">Пройти тест</a>
            <a class="button button-ghost" href="/teacher.html">О преподавателе</a>
        </div>
        <p class="lead">Тест поможет быстро определить уровень и понять, с чего начать занятия.</p>
    </div>
</header>

<div class="page">

<div class="section">
    <h2>Кому подойдут занятия</h2>
    <ul class="list">
        <li>проблемы со школьной программой</li>
        <li>сложности с грамматикой</li>
        <li>ребёнок не говорит</li>
        <li>подготовка к контрольным и экзаменам</li>
    </ul>
</div>

<div class="section">
    <h2>Как начать</h2>
    <ol class="steps">
        <li>Пройти тест</li>
        <li>Узнать уровень</li>
        <li>Обсудить результат с Анной</li>
        <li>Начать занятия</li>
    </ol>
</div>

<div class="section">
    <h2>Об Анне</h2>
    <ul class="list">
        <li>опыт 10+ лет</li>
        <li>работа с детьми</li>
        <li>уровень C1 (FCE)</li>
        <li>индивидуальный подход</li>
    </ul>
    <a class="button button-ghost" href="/teacher.html">Подробнее</a>
</div>

<div class="section">
    <h2>Как проходят занятия</h2>
    <div class="feature-grid">
        <div class="mini-card">
            <h3>Онлайн формат</h3>
            <p>Занятия проходят удобно из дома, без потери времени на дорогу.</p>
        </div>
        <div class="mini-card">
            <h3>Платформа ProgressMe</h3>
            <p>Современная среда для уроков, материалов и прогресса.</p>
        </div>
        <div class="mini-card">
            <h3>Интерактивные задания</h3>
            <p>Больше вовлечённости: практика, примеры, упражнения на уроке.</p>
        </div>
        <div class="mini-card">
            <h3>Домашняя работа</h3>
            <p>Закрепляем результат, чтобы знания переходили в навык.</p>
        </div>
        <div class="mini-card">
            <h3>Обратная связь</h3>
            <p>Анна объясняет, корректирует и помогает двигаться по шагам.</p>
        </div>
    </div>
</div>

<div class="section">
    <h2>AI-инструменты</h2>
    <ul class="list">
        <li>помогают определить уровень</li>
        <li>помогают подобрать программу</li>
        <li>усиливают обучение, но не заменяют преподавателя</li>
    </ul>

    <div class="cards">

        <div class="card">
            <h3>🧠 Подобрать программу</h3>
            <p>AI определит уровень и предложит персональный план обучения</p>
            <a class="button" href="https://english-with-anna-ai-1023185279452.us-west1.run.app" target="_blank">
                Начать
            </a>
        </div>

        <div class="card">
            <h3>🎮 AI-приложения</h3>
            <p>Интересные и креативные AI-эксперименты</p>
            <a class="button" href="/ai.html">
                Открыть
            </a>
        </div>

    </div>
</div>

<!--
<div class="section">
    <h2>Об обучении</h2>

    <p>
    Anna English AI — это онлайн-платформа для изучения английского языка, 
    объединяющая искусственный интеллект и обучение с преподавателем.
    </p>

    <p>
    Система помогает определить уровень знаний, подобрать персональную программу обучения 
    и сопровождать ученика на каждом этапе — от первого теста до уверенного владения языком.
    </p>

    <p>
    Платформа включает AI-ассистента, тестирование уровня, обучающие материалы 
    и возможность занятий с преподавателем, что делает обучение более гибким и эффективным.
    </p>

    <p>
    Подробнее о преподавателе и формате занятий можно узнать 
    <a href="/teacher.html">на этой странице</a>.
    </p>
</div>

<div class="section">
    <h2>С чего начать?</h2>

    <div class="cards">

        <div class="card">
            <h3>🧠 Подобрать программу</h3>
            <p>AI определит уровень и предложит персональный план обучения</p>
            <a class="button" href="https://english-with-anna-ai-1023185279452.us-west1.run.app" target="_blank">
                Начать
            </a>
        </div>

        <div class="card">
            <h3>📝 Пройти тест</h3>
            <p>Быстрый тест для определения уровня английского</p>
            <a class="button" onclick="openTestModal()">Пройти</a>
        </div>

        <div class="card">
            <h3>🎮 AI-приложения</h3>
            <p>Интересные и креативные AI-эксперименты</p>
            <a class="button" href="/ai.html">
                Открыть
            </a>
        </div>

        <div class="card">
            <h3>👩‍🏫 О преподавателе</h3>
            <p>Узнайте больше об Анне и её подходе к обучению</p>
            <a class="button" href="/teacher.html">
                Подробнее
            </a>
        </div>

    </div>
</div>
-->

<!-- EMAIL -->
<div class="section contact-cta">
    <h2>Напишите Анне, чтобы обсудить обучение</h2>
    <p class="lead">Коротко опишите цель (школа, оценки, экзамены) — Анна ответит и подскажет формат.</p>

    <p>
        По всем вопросам вы можете написать Анне электронную почту:
    </p>

    <p style="font-size:20px; font-weight:bold; margin:15px 0;">
        2.anna@mail.ru
    </p>

    <p style="font-size:14px; opacity:0.85;">
        Ответим, поможем подобрать обучение и подскажем оптимальный формат
    </p>

    <div class="hero-actions" style="justify-content:center; margin-top:14px;">
        <a href="https://t.me/anna_english_ai" target="_blank" class="button button-ghost">
            Написать в Telegram
        </a>
    </div>
</div>

<div class="section muted-footer">
    <h2>Другие проекты</h2>
</div>

</div>

<!-- МОДАЛКА -->
<div id="testModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:1000;">
    <div class="modal-box">
        
        <h3>Прохождение теста</h3>

        <input id="userName" type="text" placeholder="Ваше имя" class="modal-input">

        <input id="userContact" type="text" placeholder="Email или телефон (необязательно)" class="modal-input">
               
        
        <button id="testButton" onclick="startTest()" class="modal-button">Продолжить</button>

        <div id="testResult" style="margin-top:20px;"></div>

        <br>
        <button onclick="closeTestModal()" class="modal-back">← Вернуться на главную</button>

    </div>
</div>

<script>
const accounts = [
    {email:"test.english1@mail.ru", password:"0405", name:"Test1"},
    {email:"test.english2@mail.ru", password:"0506", name:"Test2"},
    {email:"test.english3@mail.ru", password:"2332", name:"Test3"},
    {email:"test.english4@mail.ru", password:"2991", name:"Test4"},
    {email:"test.english5@mail.ru", password:"9888", name:"Test5"},
    {email:"test.english6@mail.ru", password:"4393", name:"Test6"},
    {email:"test.english7@mail.ru", password:"5572", name:"Test7"},
];

function openTestModal() {
    document.getElementById("testModal").style.display = "block";
}

function closeTestModal() {
    document.getElementById("testModal").style.display = "none";
}

let testReady = false;
let savedLoginUrl = "";

async function startTest() {

    // 👉 2-й клик — открываем тест
    if (testReady) {
        window.open(savedLoginUrl, "_blank");
        return;
    }

    const name = document.getElementById("userName").value;
    const contact = document.getElementById("userContact").value;

    if (!name) {
        alert("Введите имя");
        return;
    }

    const response = await fetch("/", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body:
            "get_user=1" +
            "&name=" + encodeURIComponent(name) +
            "&contact=" + encodeURIComponent(contact)
    });

    const testUser = await response.text();

    const acc = accounts.find(a => a.name === testUser) || accounts[0];

    const loginUrl = `https://progressme.ru/login?email=${acc.email}&redirect=https://progressme.ru/classroom/2170177/lesson/1902485/section/8956326`;

    // сохраняем ссылку
    savedLoginUrl = loginUrl;

    // показываем логин/пароль (БЕЗ второй кнопки)
    document.getElementById("testResult").innerHTML = `
        <p>Вы входите как: <b>${acc.name}</b></p>
        <p>Пароль: <b>${acc.password}</b></p>
    `;

    // меняем текст кнопки
    document.getElementById("testButton").innerText = "Открыть тест";

    testReady = true;

    const message = `
🟡 English Test STARTED
Имя: ${name}
Контакт: ${contact || "не указан"}
Логин: ${acc.name}
    `;

    await fetch("/", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: "message=" + encodeURIComponent(message)
    });
}

</script>

</body>
</html>
