@echo off
title Stopping MyStock
color 0C
echo Stopping XAMPP services...
cd /d "C:\xampp"
start "" "C:\xampp\xampp_stop.exe"
timeout /t 3 /nobreak >nul
echo Services stopped.
timeout /t 2 /nobreak >nul
exit