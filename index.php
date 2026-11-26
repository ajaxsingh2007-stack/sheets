<?php
session_start();

if (!empty($_SESSION["logged_in"])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST["username"] ?? "";
    $password = $_POST["password"] ?? "";

    if ($username === "ajax" && $password === "123") {
        session_regenerate_id(true);
        $_SESSION["logged_in"] = true;
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Incorrect username or password!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>AjaxSheet Login</title>
    <style>
        body {
            font-family: Arial;
            background: #f1f5f9;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        .login-box {
            background: white;
            padding: 35px;
            width: 300px;
            border-radius: 12px;
        }

        input, button {
            width: 100%;
            padding: 12px;
            margin-top: 12px;
            box-sizing: border-box;
        }

        button {
            background: #2563eb;
            color: white;
            border: none;
            cursor: pointer;
        }

        .error {
            color: red;
        }
    </style>
</head>

<body>
<div class="login-box">
    <h2>AjaxSheet</h2>
    <p>Login to your spreadsheet</p>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Login</button>
    </form>
</div>
</body>
</html>