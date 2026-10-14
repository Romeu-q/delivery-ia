<?php

use Illuminate\Support\Facades\Schedule;

// A base fictícia é relativa à data de geração: renovar todo dia mantém "hoje" e "este mês" com pedidos
Schedule::command('demo:refresh --force')->dailyAt('03:00')->withoutOverlapping();
