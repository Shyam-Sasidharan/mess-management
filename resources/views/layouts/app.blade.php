<!doctype html>
<html lang="en"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title', 'Dashboard') · {{ \App\Models\Setting::value('business_name', 'Golden Mess') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/2.3.1/css/dataTables.bootstrap5.min.css" rel="stylesheet">@vite(['resources/css/app.css','resources/js/app.js']) @stack('styles')
</head><body>
@php
    $businessName = \App\Models\Setting::value('business_name', 'Golden Mess');
    $businessLogo = \App\Models\Setting::value('business_logo');
    $businessLogoUrl = $businessLogo && \Illuminate\Support\Facades\Storage::disk('public')->exists($businessLogo)
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($businessLogo)
        : null;
@endphp
<aside class="sidebar">
    <div class="sidebar-brand">@if($businessLogoUrl)<img class="brand-logo" src="{{ $businessLogoUrl }}" alt="{{ $businessName }} logo">@else<span class="brand-mark">{{ strtoupper(substr($businessName, 0, 2)) }}</span>@endif<span>{{ \Illuminate\Support\Str::limit($businessName, 18) }}</span></div>
    <nav class="nav flex-column">
        <div class="nav-label">Overview</div><a class="nav-link {{ request()->routeIs('dashboard')?'active':'' }}" href="{{ route('dashboard') }}"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>
        @if(auth()->user()->hasPermission('manage-business'))
        <div class="nav-label">Business</div><a class="nav-link {{ request()->routeIs('customers.*')?'active':'' }}" href="{{ route('customers.index') }}"><i class="bi bi-people"></i> Customers</a>
        <a class="nav-link {{ request()->routeIs('payments.*')?'active':'' }}" href="{{ route('payments.index') }}"><i class="bi bi-wallet2"></i> Payments</a><a class="nav-link {{ request()->routeIs('expenses.*')?'active':'' }}" href="{{ route('expenses.index') }}"><i class="bi bi-receipt"></i> Expenses</a>
        <a class="nav-link {{ request()->routeIs('holidays.*')?'active':'' }}" href="{{ route('holidays.index') }}"><i class="bi bi-calendar-event"></i> Holiday Calendar</a><a class="nav-link {{ request()->routeIs('meal-holds.*')?'active':'' }}" href="{{ route('meal-holds.index') }}"><i class="bi bi-pause-circle"></i> Meal Holds</a>
        @endif
        <a class="nav-link {{ request()->routeIs('deliveries.*')?'active':'' }}" href="{{ route('deliveries.index') }}"><i class="bi bi-truck"></i> Deliveries</a>
        @if(auth()->user()->hasPermission('manage-business'))
        <div class="nav-label">Intelligence</div><a class="nav-link {{ request()->routeIs('reports.*')?'active':'' }}" href="{{ route('reports.index') }}"><i class="bi bi-bar-chart"></i> Reports & Analytics</a>
        <a class="nav-link {{ request()->routeIs('settings.*')?'active':'' }}" href="{{ route('settings.index') }}"><i class="bi bi-gear"></i> Settings</a>@endif
    </nav>
</aside>
<div class="sidebar-backdrop" data-sidebar-close></div>
<main class="main">
    <header class="topbar"><button class="btn btn-light mobile-menu me-2" data-sidebar-toggle><i class="bi bi-list"></i></button>
        <form action="{{ route('search') }}" class="d-none d-md-flex" style="max-width:420px;width:100%"><div class="input-group"><span class="input-group-text bg-transparent border-end-0"><i class="bi bi-search"></i></span><input name="q" class="form-control border-start-0" placeholder="Search customers, mobile, vendors…"></div></form>
        <div class="ms-auto d-flex align-items-center gap-3"><a class="btn btn-light position-relative" href="{{ route('notifications.index') }}"><i class="bi bi-bell"></i>@if(auth()->user()->unreadNotifications()->count())<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">{{ auth()->user()->unreadNotifications()->count() }}</span>@endif</a>
            <div class="dropdown"><button class="btn btn-light dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown"><span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,1)) }}</span><span class="d-none d-sm-inline">{{ auth()->user()->name }}</span></button><div class="dropdown-menu dropdown-menu-end p-2"><div class="px-2 py-1 text-muted small">{{ auth()->user()->role?->name }}</div><form method="post" action="{{ route('logout') }}">@csrf<button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Logout</button></form></div></div>
        </div></header>
    <div class="content slide-in">@yield('content')</div>
</main>
<nav class="mobile-bottom-nav no-print" aria-label="Mobile navigation"><a class="{{ request()->routeIs('dashboard')?'active':'' }}" href="{{ route('dashboard') }}"><i class="bi bi-house-door"></i><span>Home</span></a>@if(auth()->user()->hasPermission('manage-business'))<a class="{{ request()->routeIs('customers.*')?'active':'' }}" href="{{ route('customers.index') }}"><i class="bi bi-people"></i><span>Customers</span></a>@endif<a class="{{ request()->routeIs('deliveries.*')?'active':'' }}" href="{{ route('deliveries.index') }}"><i class="bi bi-truck"></i><span>Deliveries</span></a>@if(auth()->user()->hasPermission('manage-business'))<a class="{{ request()->routeIs('payments.*')?'active':'' }}" href="{{ route('payments.index') }}"><i class="bi bi-wallet2"></i><span>Payments</span></a>@endif<button type="button" data-sidebar-toggle><i class="bi bi-grid"></i><span>More</span></button></nav>
<div class="toast-container position-fixed top-0 end-0 p-3">@if(session('success'))<div class="toast show border-0 shadow"><div class="toast-header"><span class="rounded me-2 bg-success" style="width:10px;height:10px"></span><strong class="me-auto">Success</strong><button class="btn-close" data-bs-dismiss="toast"></button></div><div class="toast-body">{{ session('success') }}</div></div>@endif @if($errors->any())<div class="toast show border-0 shadow"><div class="toast-header"><span class="rounded me-2 bg-danger" style="width:10px;height:10px"></span><strong class="me-auto">Please check the form</strong><button class="btn-close" data-bs-dismiss="toast"></button></div><div class="toast-body">{{ $errors->first() }}</div></div>@endif</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"></script><script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="https://cdn.datatables.net/2.3.1/js/dataTables.min.js"></script><script src="https://cdn.datatables.net/2.3.1/js/dataTables.bootstrap5.min.js"></script><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js"></script>
<script>$(function(){ $('.data-table').each(function(){ if (!this.tBodies.length || this.tBodies[0].querySelector('td[colspan]')) return; new DataTable(this,{paging:false,info:false,searching:false,order:[]}); }); });</script>@stack('scripts')</body></html>
