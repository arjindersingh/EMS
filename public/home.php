<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EMS Home</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 2rem;
            line-height: 1.6;
            color: #333;
        }

        .card {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #f9f9f9;
        }

        code {
            background: #eee;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Welcome to EMShhhhh</h1>
        <p>The application has completed its initial check and is ready to continue.</p>
        <p>Database connection is active and the home page has been loaded successfully.</p>
        <p><a href="/admin">Go to admin panel</a></p>

        <?php if (!empty($config)): ?>
            <h2>Database Configuration</h2>
            <pre><?php echo htmlspecialchars(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?></pre>
        <?php endif; ?>
    </div>
</body>
</html>
