<?php
/** Shell for the signed-out admin pages (login, first password, forgot). $title, $heading, $lead. */
$title = $title ?? 'Log in';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> · Maa Sarada Admin</title>
<link rel="icon" type="image/png" href="<?= base_url('assets/image/favicon.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
<script src="https://unpkg.com/lucide@0.544.0/dist/umd/lucide.min.js"></script>
</head>
<body class="auth-body">
<div class="auth-wrap">
  <aside class="auth-side">
    <div class="brandmark"><i data-lucide="gem"></i></div>
    <div class="auth-brand">Maa Sarada</div>
    <div class="auth-tag">Marble &amp; stone, in harmony.</div>
    <ul class="auth-points">
      <li><i data-lucide="shield-check"></i>Each staff member has their own login</li>
      <li><i data-lucide="key-round"></i>Passwords are never shared or shown again</li>
      <li><i data-lucide="mail"></i>Forgot it? A code comes to your email</li>
    </ul>
  </aside>
  <section class="auth-card glass">
    <div class="eyebrow"><?= e($eyebrow ?? 'Staff area') ?></div>
    <h1><?= e($heading ?? 'Log in') ?><span style="color:#dca71f">.</span></h1>
    <?php if (!empty($lead)): ?><p class="auth-lead"><?= $lead ?></p><?php endif; ?>
    <?php foreach (['error' => 'bad', 'warning' => 'warn', 'success' => 'ok', 'info' => 'info'] as $k => $cls):
        $m = session()->get($k); if (!is_string($m) || $m === '') continue; ?>
      <div class="auth-msg <?= $cls ?>" role="<?= $k === 'error' ? 'alert' : 'status' ?>"><i data-lucide="<?= ['bad' => 'circle-alert', 'warn' => 'triangle-alert', 'ok' => 'circle-check', 'info' => 'info'][$cls] ?>"></i><span><?= e($m) ?></span></div>
    <?php endforeach; ?>
