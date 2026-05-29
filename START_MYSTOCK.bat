::[Bat To Exe Converter]
::
::fBE1pAF6MU+EWHreyFoxJQtEcAyWOGS5FYkx8dvv4OmMnn4pddALR6Li6YChA8td6ETwFQ==
::fBE1pAF6MU+EWHreyFoxJQtEcAyWOGS5FYkx8dvv4OmMnn4pe9IAVbbo7putC64R61GE
::YAwzoRdxOk+EWAjk
::fBw5plQjdCyDJEGF+VIgFBNASAuBL1eXH4YI5+vw08eekVkSV+lxcYzUug==
::YAwzuBVtJxjWCl3EqQJgSA==
::ZR4luwNxJguZRRnk
::Yhs/ulQjdF+5
::cxAkpRVqdFKZSzk=
::cBs/ulQjdF65
::ZR41oxFsdFKZSDk=
::eBoioBt6dFKZSDk=
::cRo6pxp7LAbNWATEpCI=
::egkzugNsPRvcWATEpCI=
::dAsiuh18IRvcCxnZtBNQ
::cRYluBh/LU+EWAnk
::YxY4rhs+aU+IeA==
::cxY6rQJ7JhzQF1fEqQJhSA==
::ZQ05rAF9IBncCkqN+0xwdVsFAlTMbAs=
::ZQ05rAF9IAHYFVzEqQIdMShAQweJXA==
::eg0/rx1wNQPfEVWB+kM9LVsJDCmbD3+1Bb5Rxen17u2CsC0=
::fBEirQZwNQPfEVWB+kM9LVsJDCmbD3+1Bb58
::cRolqwZ3JBvQF1fEqQJQ
::dhA7uBVwLU+EWAqptGQxJRpYVWQ=
::YQ03rBFzNR3SWATE2QQYSA==
::dhAmsQZ3MwfNWATEp29wDhpZRQibXA==
::ZQ0/vhVqMQ3MEVWAtB9wSA==
::Zg8zqx1/OA3MEVWAtB9wSA==
::dhA7pRFwIByZRRnk
::Zh4grVQjdCyDJGyX8VAjFEh5DAKDMWK2H4k47fvw++WXnmAEZ/Ywe4Tk97WAIecW+AvhbZNN
::YB416Ek+ZW8=
::
::
::978f952a14a936cc963da21a135fa983
@echo off
title MyStock - Stock Management System
color 0A

:: Get the directory where this batch file is located
set "SCRIPT_DIR=%~dp0"
cd /d "%SCRIPT_DIR%"

echo ========================================
echo    MyStock Stock Management System
echo ========================================
echo.

:: Check if XAMPP exists
if not exist "C:\xampp\apache\bin\httpd.exe" (
    echo [ERROR] XAMPP not found at C:\xampp
    echo Please install XAMPP first.
    echo.
    pause
    exit /b 1
)

echo [1/4] Starting XAMPP services...

:: Start Apache using XAMPP control script
cd /d "C:\xampp"
start "" "C:\xampp\xampp_start.exe"

:: Wait for services
timeout /t 5 /nobreak >nul

echo [2/4] Waiting for services to be ready...
timeout /t 3 /nobreak >nul

echo [3/4] Opening MyStock in your browser...
start http://localhost/MyStock/

echo [4/4] MyStock is now running!
echo.
echo ========================================
echo    MyStock is Ready!
echo    Browser should open automatically.
echo ========================================
echo.
echo To stop MyStock, run the STOP_MYSTOCK.exe
echo.

:: Keep window open for 3 seconds then auto-close
timeout /t 3 /nobreak >nul
exit