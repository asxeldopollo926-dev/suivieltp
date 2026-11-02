<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>En Savoir Plus - LTP</title>
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
            min-height: 100vh;
            background: #f8f9fb;
        }
        .header {
            background: #002688;
            padding: 15px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            flex-wrap: wrap;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .header-left svg {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            padding: 4px;
        }
        .site-name {
            font-size: 1.4em;
            font-weight: bold;
        }
        .header-right {
            display: flex;
            gap: 30px;
            align-items: center;
        }
        .header-right a {
            color: white;
            text-decoration: none;
            font-size: 1.1em;
            transition: opacity 0.2s;
            position: relative;
            padding: 5px 0;
        }
        .header-right a::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: 0;
            left: 0;
            background-color: white;
            transition: width 0.3s ease;
        }
        .header-right a:hover::after,
        .header-right a.active::after {
            width: 100%;
        }
        .header-right a:hover {
            opacity: 1;
        }
        .admin-icon {
            width: 24px;
            height: 24px;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .admin-icon:hover {
            opacity: 0.8;
        }

        .main-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: calc(100vh - 70px);
            width: 100%;
            max-width: 1200px;
            margin: 70px auto 0;
            padding: 40px 20px;
            gap: 40px;
        }

        .info-section {
            background: white;
            border-radius: 40px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06);
            padding: 48px;
            width: 100%;
            max-width: 800px;
            text-align: center;
        }

        .section-title {
            font-size: 2em;
            color: #002688;
            margin-bottom: 30px;
            font-weight: bold;
        }

        .section-description {
            font-size: 1.2em;
            color: #444;
            line-height: 1.6;
            margin-bottom: 40px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }

        .feature-card {
            background: #f8f9fb;
            padding: 30px;
            border-radius: 20px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-5px);
        }

        .feature-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 20px;
            color: #002688;
        }

        .feature-icon svg {
            width: 100%;
            height: 100%;
        }

        .feature-title {
            font-size: 1.2em;
            color: #002688;
            margin-bottom: 15px;
            font-weight: bold;
        }

        .feature-description {
            color: #666;
            line-height: 1.5;
        }

        .cta-section {
            text-align: center;
            margin-top: 20px;
        }

        .cta-button {
            display: inline-block;
            background: #002688;
            color: white;
            text-decoration: none;
            padding: 15px 40px;
            border-radius: 30px;
            font-size: 1.1em;
            font-weight: bold;
            transition: background 0.3s ease;
            margin: 10px;
        }

        .cta-button:hover {
            background: #001f5c;
        }

        .cta-button.secondary {
            background: #6c757d;
            cursor: not-allowed;
        }

        .coming-soon {
            font-size: 0.9em;
            color: #666;
            margin-top: 10px;
        }

        @media (max-width: 768px) {
            .main-wrapper {
                padding: 20px;
            }
            .info-section {
                padding: 30px;
                border-radius: 20px;
            }
            .section-title {
                font-size: 1.6em;
            }
            .section-description {
                font-size: 1.1em;
            }
            .features-grid {
                grid-template-columns: 1fr;
            }
        }

        .dropdown {
            position: relative;
            display: inline-block;
        }
        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background-color: #002688;
            min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 1001;
            border-radius: 8px;
            overflow: hidden;
        }
        .dropdown-content a {
            color: white;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            transition: background-color 0.3s;
        }
        .dropdown-content a:hover {
            background-color: #0050b3;
        }
        .dropdown:hover .dropdown-content {
            display: block;
        }
        .dropdown:hover .admin-icon {
            opacity: 0.8;
        }

        /* Menu Mobile */
        .mobile-menu {
            display: none;
            position: fixed;
            top: 0;
            right: 0;
            width: 80%;
            max-width: 300px;
            height: 100vh;
            background: #002688;
            flex-direction: column;
            padding: 80px 30px 30px;
            box-shadow: -5px 0 15px rgba(0,0,0,0.1);
            transform: translateX(100%);
            opacity: 0;
            transition: transform 1s ease-out, opacity 1s ease-out;
        }

        .mobile-menu.active {
            display: flex;
            transform: translateX(0);
            opacity: 1;
        }

        .mobile-menu a {
            color: white;
            text-decoration: none;
            font-size: 1.2em;
            padding: 15px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            opacity: 0;
            transform: translateX(20px);
            transition: opacity 0.3s ease-out, transform 0.3s ease-out;
            transition-delay: calc(0.1s * var(--i));
        }

        .mobile-menu.active a {
            opacity: 1;
            transform: translateX(0);
        }

        .mobile-menu a:last-child {
            border-bottom: none;
        }

        .mobile-menu a:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 10px;
            z-index: 1002;
        }

        .menu-icon, .close-icon {
            width: 24px;
            height: 24px;
            transition: all 0.3s ease-out;
        }

        .menu-icon {
            display: block;
        }

        .close-icon {
            display: none;
        }

        .mobile-menu-btn.active .menu-icon {
            display: none;
        }

        .mobile-menu-btn.active .close-icon {
            display: block;
        }

        @media (max-width: 900px) {
            .header-right {
                display: none;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            .mobile-menu {
                display: none;
            }
            
            .mobile-menu.active {
                display: flex;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-left">
            <img src="assets/logo.svg" alt="Logo SuiviLTP" style="width: 40px; height: 40px; background: white; border-radius: 50%; padding: 4px;">
            <span class="site-name">SuiviLTP</span>
        </div>

        <!-- Menu Mobile -->
        <button class="mobile-menu-btn" aria-label="Menu">
            <svg class="menu-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M4 6H20M4 12H20M4 18H20" stroke="white" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <svg class="close-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M6 18L18 6M6 6L18 18" stroke="white" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </button>
        <nav class="mobile-menu">
            <a href="bienvenue.html" style="--i: 1">Accueil</a>
            <a href="savoir_plus.php" class="active" style="--i: 2">Savoir +</a>
            <a href="login_parent.php" style="--i: 3">Parents</a>
            <a href="loginadmin.php" style="--i: 4">Administration</a>
            <a href="loginprof.php" style="--i: 5">Professeurs</a>
        </nav>

        <!-- Menu Desktop -->
        <nav class="header-right">
            <a href="bienvenue.html">Accueil</a>
            <a href="savoir_plus.php" class="active">Savoir +</a>
            <div class="dropdown">
                <svg class="admin-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 12C14.7614 12 17 9.76142 17 7C17 4.23858 14.7614 2 12 2C9.23858 2 7 4.23858 7 7C7 9.76142 9.23858 12 12 12Z" stroke="white" stroke-width="2"/>
                    <path d="M20 21C20 18.8783 19.1571 16.8434 17.6569 15.3431C16.1566 13.8429 14.1217 13 12 13C9.87827 13 7.84344 13.8429 6.34315 15.3431C4.84285 16.8434 4 18.8783 4 21" stroke="white" stroke-width="2"/>
                </svg>
                <div class="dropdown-content">
                    <a href="loginadmin.php">Administration</a>
                    <a href="loginprof.php">Professeurs</a>
                </div>
            </div>
        </nav>
    </header>

    <div class="main-wrapper">
        <div class="info-section">
            <h1 class="section-title">À propos de SuiviLTP</h1>
            <p class="section-description">
                SuiviLTP est une plateforme innovante conçue pour moderniser le suivi scolaire au sein du Lycée Technique et Professionnel de Tchaourou. Notre mission est de faciliter la communication entre l'établissement et les parents d'élèves.
            </p>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M9 17H15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M9 13H15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M9 9H15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">Suivi des Notes</h3>
                    <p class="feature-description">Accédez en temps réel aux bulletins et aux résultats scolaires de votre enfant.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                            <path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M3 10H21" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">Présence</h3>
                    <p class="feature-description">Suivez l'assiduité de votre enfant et recevez des notifications en cas d'absence.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="2" y="5" width="20" height="14" rx="2" stroke="currentColor" stroke-width="2"/>
                            <path d="M2 10H22" stroke="currentColor" stroke-width="2"/>
                            <path d="M6 15H10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M14 15H18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">Paiements</h3>
                    <p class="feature-description">Consultez l'état des paiements et l'historique des transactions.</p>
                </div>
            </div>

            <div class="cta-section">
                <a href="http://www.lyceetchaourou.bj" class="cta-button" target="_blank">
                    En savoir plus sur l'établissement
                </a>
            </div>
        </div>
    </div>

    <script>
    // Mobile menu functionality
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const mobileMenu = document.querySelector('.mobile-menu');

    mobileMenuBtn.addEventListener('click', () => {
        mobileMenuBtn.classList.toggle('active');
        mobileMenu.classList.toggle('active');
    });

    // Close menu when clicking outside
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 900 && 
            !e.target.closest('.mobile-menu') && 
            !e.target.closest('.mobile-menu-btn')) {
            mobileMenuBtn.classList.remove('active');
            mobileMenu.classList.remove('active');
        }
    });
    </script>
</body>
</html> 