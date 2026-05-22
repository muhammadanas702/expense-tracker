<?php
// index.php
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>ExpenseFlow | Professional Expense Management</title>

<link rel="preconnect"
      href="https://fonts.googleapis.com">

<link rel="preconnect"
      href="https://fonts.gstatic.com"
      crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
      rel="stylesheet">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>

:root{

    --primary:#0B2545;
    --teal:#137A7F;
    --ocean:#134074;
    --light:#EEF4F8;
    --white:#FFFFFF;
    --muted:#5F6C7D;
}

/* GLOBAL */

*{

    margin:0;
    padding:0;
    box-sizing:border-box;

    font-family:'Inter',sans-serif;
}

html{

    scroll-behavior:smooth;
}

body {

    min-height: 100vh;

    background-color: #f4f7f9;

    background-image:

        radial-gradient(
            circle at 50% 30%,
            #ffffff 0%,
            transparent 70%
        ),

        linear-gradient(
            to right,
            rgba(19, 122, 127, 0.04) 1px,
            transparent 1px
        ),

        linear-gradient(
            to bottom,
            rgba(19, 122, 127, 0.04) 1px,
            transparent 1px
        );

    background-size:
        100% 100%,
        25px 25px,
        25px 25px;

    color:var(--primary);

    overflow-x:hidden;
}

.container{

    width:90%;
    max-width:1300px;

    margin:auto;
}

/* HEADER */

header{

    position:sticky;
    top:0;
    z-index:999;

    backdrop-filter:blur(18px);

    background:
    rgba(255,255,255,0.7);

    border-bottom:
    1px solid rgba(19,122,127,0.08);
}

.navbar{

    display:flex;

    justify-content:space-between;

    align-items:center;

    padding:20px 0;
}

.logo{

    display:flex;

    align-items:center;

    gap:12px;

    text-decoration:none;
}

.logo-icon{

    width:52px;
    height:52px;

    border-radius:16px;

    background:
    linear-gradient(
        135deg,
        var(--primary),
        var(--teal)
    );

    display:flex;

    align-items:center;

    justify-content:center;

    color:white;

    font-size:24px;

    box-shadow:
    0 10px 30px rgba(19,122,127,0.2);
}

.logo-text{

    font-size:38px;

    font-weight:800;

    letter-spacing:-1px;
}

.logo-text span{

    color:var(--teal);

    font-weight:500;
}

.nav-links{

    display:flex;

    align-items:center;

    gap:35px;
}

.nav-links a{

    text-decoration:none;

    color:var(--muted);

    font-weight:600;

    transition:0.3s;
}

.nav-links a:hover{

    color:var(--primary);
}

.login-btn{

    padding:12px 22px;

    border-radius:14px;

    border:
    1px solid rgba(19,122,127,0.2);

    background:white;

    color:var(--primary);

    transition:0.3s;
}

.login-btn:hover{

    background:var(--primary);

    color:white;
}

.register-btn{

    padding:12px 24px;

    border-radius:14px;

    background:
    linear-gradient(
        135deg,
        var(--primary),
        var(--teal)
    );

    color:white !important;

    box-shadow:
    0 12px 25px rgba(19,122,127,0.25);
}

.register-btn:hover{

    transform:translateY(-2px);
}

/* HERO */

.hero{

    padding:
    90px 0 120px;
}

.hero-box{

    background:
    rgba(255,255,255,0.55);

    backdrop-filter:blur(16px);

    border:
    1px solid rgba(19,122,127,0.08);

    border-radius:40px;

    overflow:hidden;

    box-shadow:
    0 25px 80px rgba(11,37,69,0.08);

    display:grid;

    grid-template-columns:
    repeat(2,1fr);

    align-items:center;
}

.hero-left{

    padding:70px;
}

.badge{

    display:inline-flex;

    align-items:center;

    gap:10px;

    background:
    rgba(19,122,127,0.08);

    color:var(--teal);

    padding:
    10px 18px;

    border-radius:999px;

    font-size:14px;

    font-weight:700;

    margin-bottom:28px;
}

.hero h1{

    font-size:74px;

    line-height:1.05;

    font-weight:800;

    letter-spacing:-3px;

    margin-bottom:18px;

    color:var(--primary);
}

.hero h1 span{

    color:var(--teal);

    font-weight:500;
}

.hero-sub{

    font-size:18px;

    color:var(--muted);

    line-height:1.8;

    margin-bottom:35px;

    max-width:600px;
}

.hero-buttons{

    display:flex;

    gap:18px;

    flex-wrap:wrap;
}

.primary-btn{

    padding:16px 30px;

    border-radius:18px;

    text-decoration:none;

    font-weight:700;

    background:
    linear-gradient(
        135deg,
        var(--primary),
        var(--teal)
    );

    color:white;

    transition:0.3s;

    box-shadow:
    0 15px 30px rgba(19,122,127,0.2);
}

.primary-btn:hover{

    transform:translateY(-3px);
}

.secondary-btn{

    padding:16px 30px;

    border-radius:18px;

    text-decoration:none;

    font-weight:700;

    border:
    1px solid rgba(11,37,69,0.1);

    background:white;

    color:var(--primary);
}

.hero-right{

    height:100%;

    min-height:600px;

    background:
    linear-gradient(
        rgba(255,255,255,0.2),
        rgba(255,255,255,0.2)
    ),

    url('https://images.unsplash.com/photo-1554224155-6726b3ff858f?q=80&w=1200&auto=format&fit=crop');

    background-size:cover;
    background-position:center;

    position:relative;
}

.overlay-card{

    position:absolute;

    bottom:40px;
    left:40px;

    background:
    rgba(255,255,255,0.8);

    backdrop-filter:blur(12px);

    padding:25px;

    border-radius:24px;

    width:320px;

    box-shadow:
    0 20px 40px rgba(0,0,0,0.08);
}

.overlay-card h3{

    color:var(--primary);

    margin-bottom:12px;

    font-size:22px;
}

.overlay-card p{

    color:var(--muted);

    line-height:1.7;
}

/* FEATURES */

.section{

    padding:100px 0;
}

.section-title{

    text-align:center;

    font-size:52px;

    font-weight:800;

    color:var(--primary);

    margin-bottom:16px;

    letter-spacing:-2px;
}

.section-sub{

    text-align:center;

    color:var(--muted);

    max-width:700px;

    margin:auto auto 60px;

    line-height:1.8;
}

.features-grid{

    display:grid;

    grid-template-columns:
    repeat(auto-fit,minmax(260px,1fr));

    gap:30px;
}

.feature-card{

    background:
    rgba(255,255,255,0.65);

    border:
    1px solid rgba(19,122,127,0.08);

    border-radius:28px;

    padding:40px 30px;

    transition:0.3s;

    backdrop-filter:blur(14px);
}

.feature-card:hover{

    transform:translateY(-8px);

    box-shadow:
    0 25px 50px rgba(11,37,69,0.08);
}

.feature-icon{

    width:72px;
    height:72px;

    border-radius:20px;

    background:
    linear-gradient(
        135deg,
        var(--primary),
        var(--teal)
    );

    display:flex;

    align-items:center;

    justify-content:center;

    color:white;

    font-size:28px;

    margin-bottom:25px;
}

.feature-card h3{

    font-size:24px;

    margin-bottom:14px;

    color:var(--primary);
}

.feature-card p{

    color:var(--muted);

    line-height:1.8;
}

/* FOOTER */

footer{

    margin-top:80px;

    padding:50px 0;

    background:
    rgba(255,255,255,0.7);

    backdrop-filter:blur(10px);

    border-top:
    1px solid rgba(19,122,127,0.08);
}

.footer-content{

    display:flex;

    justify-content:space-between;

    align-items:center;

    gap:20px;

    flex-wrap:wrap;
}

.footer-text{

    color:var(--muted);
}

.footer-links{

    display:flex;

    gap:25px;
}

.footer-links a{

    text-decoration:none;

    color:var(--muted);

    font-weight:600;
}

/* MOBILE */

@media(max-width:1000px){

    .hero-box{

        grid-template-columns:1fr;
    }

    .hero-right{

        min-height:450px;
    }

    .hero h1{

        font-size:58px;
    }
}

@media(max-width:768px){

    .navbar{

        flex-direction:column;

        gap:20px;
    }

    .nav-links{

        flex-wrap:wrap;

        justify-content:center;
    }

    .hero-left{

        padding:40px 25px;
    }

    .hero h1{

        font-size:44px;
    }

    .section-title{

        font-size:38px;
    }

    .overlay-card{

        width:85%;

        left:50%;
        transform:translateX(-50%);
    }
}

</style>

</head>

<body>

<header>

<div class="container">

<div class="navbar">

<a href="#"
class="logo">

<div class="logo-icon">
<i class="fa-solid fa-wave-square"></i>
</div>

<div class="logo-text">
Expense<span>Flow</span>
</div>

</a>

<div class="nav-links">

<a href="#">Home</a>

<a href="#features">Features</a>

<a href="/expense-tracker/auth/login.php"
class="login-btn">
Login
</a>

<a href="/expense-tracker/auth/register.php"
class="register-btn">
Get Started
</a>

</div>

</div>

</div>

</header>

<!-- HERO -->

<section class="hero">

<div class="container">

<div class="hero-box">

<div class="hero-left">

<div class="badge">
<i class="fa-solid fa-chart-line"></i>
Professional Expense Management
</div>

<h1>
Expense<span>Flow</span>
</h1>

<p class="hero-sub">

Track your income, expenses, savings,
analytics, monthly reports and yearly
financial performance with a clean
professional fintech experience.

</p>

<div class="hero-buttons">

<a href="/expense-tracker/auth/register.php"
class="primary-btn">

Start Now

</a>

<a href="#features"
class="secondary-btn">

Explore Features

</a>

</div>

</div>

<div class="hero-right">

<div class="overlay-card">

<h3>
Smart Financial Dashboard
</h3>

<p>

Income tracking, expense reports,
analytics charts, savings monitoring
and professional financial management
all in one platform.

</p>

</div>

</div>

</div>

</div>

</section>

<!-- FEATURES -->

<section id="features"
class="section">

<div class="container">

<h2 class="section-title">
Powerful Features
</h2>

<p class="section-sub">

Everything you built till now is preserved.
This new interface only upgrades the UI
without disturbing your old working system.

</p>

<div class="features-grid">

<div class="feature-card">

<div class="feature-icon">
<i class="fa-solid fa-wallet"></i>
</div>

<h3>
Income Tracking
</h3>

<p>

Add and manage income with
currency support and automatic
date-time tracking.

</p>

</div>

<div class="feature-card">

<div class="feature-icon">
<i class="fa-solid fa-money-bill-wave"></i>
</div>

<h3>
Expense Management
</h3>

<p>

Track categorized expenses
with smart monthly and yearly
reports.

</p>

</div>

<div class="feature-card">

<div class="feature-icon">
<i class="fa-solid fa-chart-pie"></i>
</div>

<h3>
Analytics Dashboard
</h3>

<p>

Beautiful charts, graphs and
financial insights with advanced
report system.

</p>

</div>

<div class="feature-card">

<div class="feature-icon">
<i class="fa-solid fa-shield-halved"></i>
</div>

<h3>
Secure Authentication
</h3>

<p>

Password hashing, forgot password,
professional login and secure
user management system.

</p>

</div>

</div>

</div>

</section>

<!-- FOOTER -->

<footer>

<div class="container">

<div class="footer-content">

<div>

<div class="logo-text">
Expense<span>Flow</span>
</div>

<p class="footer-text"
style="margin-top:10px;">

Professional Expense Management System
<br>
Developed by Muhammad Anas | 2026

</p>

</div>

<div class="footer-links">

<a href="#">
Home
</a>

<a href="#features">
Features
</a>

<a href="/expense-tracker/auth/login.php">
Login
</a>

</div>

</div>

</div>

</footer>

</body>
</html>