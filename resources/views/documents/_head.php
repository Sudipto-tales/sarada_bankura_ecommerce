<?php /** Shared <head> for printable documents and public order pages. $docTitle required. */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<title><?= e($docTitle) ?></title>
<link rel="icon" type="image/png" href="<?= base_url('assets/image/favicon.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= base_url('assets/css/documents.css') ?>">
</head>
