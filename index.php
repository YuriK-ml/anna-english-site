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
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 50px;
            background-image: url('images/London.webp');
            background-repeat: no-repeat;
            background-position: center center;
            background-attachment: fixed;
            background-size: cover;
            color: #fff;
        }

        h1 { text-shadow: 1px 1px 4px #000; }
        p { font-size: 18px; text-shadow: 1px 1px 3px #000; }

        .section {
            margin: 40px 0;
            background-color: rgba(0,0,0,0.6);
            padding: 25px;
            border-radius: 12px;
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
            background-color: #3498db;
            text-decoration: none;
            border-radius: 8px;
            transition: 0.3s;
            cursor: pointer;
        }

        .button:hover { background-color: #2980b9; }

        .card p { font-size: 14px; margin: 10px 0; }
        
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

<h1>Anna English AI</h1>
<p>Подбери обучение английскому с помощью AI</p>


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

<!-- EMAIL -->
<div class="section">
    <h2>Связаться с преподавателем</h2>

    <p>
        По всем вопросам вы можете написать Анне электронную почту:
    </p>

    <p style="font-size:20px; font-weight:bold; margin:15px 0;">
        2.anna@mail.ru
    </p>

    <p style="font-size:14px; opacity:0.85;">
        Ответим, поможем подобрать обучение и подскажем оптимальный формат
    </p>
</div>

<!-- TELEGRAM -->
<div style="margin-top:30px; opacity:0.7;">
    <a href="https://t.me/anna_english_ai" target="_blank" style="color:#ccc; font-size:14px; text-decoration:none;">
        Telegram канал (дополнительно)
    </a>
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