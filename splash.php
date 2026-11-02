<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SuiviLTP - Chargement</title>
    <style>
        @font-face {
            font-family: 'Canaro';
            src: url('https://fonts.cdnfonts.com/s/17309/Canaro-Light.woff') format('woff');
            font-weight: normal;
            font-style: normal;
        }
        * {
            font-family: 'Canaro', Arial, sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background: linear-gradient(135deg, #002688 0%, #001f6b 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            padding: 20px;
        }
        .logo-container {
            text-align: center;
            margin-bottom: 40px;
            animation: fadeIn 1s ease-in;
        }
        .logo-container img {
            width: 120px;
            height: 120px;
            background: white;
            border-radius: 50%;
            padding: 20px;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }
        .logo-text {
            font-size: 3em;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .loading-bar {
            width: 200px;
            height: 4px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 2px;
            margin-top: 20px;
            overflow: hidden;
        }
        .loading-progress {
            width: 0%;
            height: 100%;
            background: white;
            animation: loading 3s ease-in-out forwards;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        @keyframes loading {
            0% { width: 0%; }
            100% { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="logo-container">
        <img src="assets/logo.svg" alt="Logo SuiviLTP" style="width: 120px; height: 120px; background: white; border-radius: 50%; padding: 20px; margin-bottom: 20px; animation: pulse 2s infinite;">
        <div class="logo-text">SuiviLTP</div>
    </div>
    
    <div class="loading-bar">
        <div class="loading-progress"></div>
    </div>

    <script>
        // Rediriger vers bienvenue.html après 3 secondes
        setTimeout(function() {
            window.location.href = 'bienvenue.html';
        }, 3000);
    </script>
</body>
</html> 