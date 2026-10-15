<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fichajes Jibble</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
        }
        .header {
            background: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 20px;
        }
        .user-details h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 3px;
        }
        .user-details p {
            font-size: 13px;
            color: #666;
        }
        .logout-btn {
            background: #f44336;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .logout-btn:hover {
            background: #da190b;
            transform: translateY(-2px);
        }
        .card {
            background: white;
            padding: 40px;
            border-radius: 0 0 15px 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .status {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            border: 1px solid #ffeaa7;
            font-weight: 600;
        }
        .status.in {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        .status.out {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        button {
            width: 100%;
            color: white;
            border: none;
            padding: 18px;
            font-size: 18px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: bold;
            margin: 10px 0;
            transition: all 0.3s;
        }
        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }
        .btn-in {
            background: linear-gradient(135deg, #4CAF50, #45a049);
        }
        .btn-in:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(76, 175, 80, 0.4);
        }
        .btn-out {
            background: linear-gradient(135deg, #f44336, #da190b);
        }
        .btn-out:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(244, 67, 54, 0.4);
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 10px;
            display: none;
            text-align: center;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 2px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #f5c6cb;
        }
        .loading {
            text-align: center;
            padding: 40px;
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .time {
            font-size: 14px;
            color: #999;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Loading state -->
        <div id="loadingScreen" class="card" style="text-align: center;">
            <div class="spinner"></div>
            <p>Verificando sesión...</p>
        </div>

        <!-- Main app (hidden until auth check) -->
        <div id="mainApp" style="display: none;">
            <div class="header">
                <div class="user-info">
                    <div class="avatar" id="userAvatar">?</div>
                    <div class="user-details">
                        <h2 id="userName">Cargando...</h2>
                        <p id="userEmail">---</p>
                    </div>
                </div>
                <button class="logout-btn" onclick="logout()">
                    🚪 Salir
                </button>
            </div>

            <div class="card">
                <h1>⏱️ Control de Fichajes</h1>
                
                <div id="status" class="status">
                    ⏳ Verificando estado...
                </div>

                <button id="btnClockIn" class="btn-in" onclick="clockIn()" disabled>
                    ➡️ Fichar Entrada
                </button>

                <button id="btnClockOut" class="btn-out" onclick="clockOut()" disabled>
                    ⬅️ Fichar Salida
                </button>

                <div id="result" class="result"></div>
                
                <div class="time">
                    <small id="currentTime"></small>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentUser = null;

        // Verificar autenticación al cargar
        window.onload = async function() {
            try {
                const response = await fetch('api.php?action=check_auth');
                const data = await response.json();
                
                if (!data.authenticated) {
                    // No autenticado - redirigir a login
                    window.location.href = 'login.html';
                    return;
                }
                
                // Usuario autenticado - mostrar app
                currentUser = data.user;
                
                document.getElementById('userName').textContent = data.user.nombre;
                document.getElementById('userEmail').textContent = data.user.email;
                
                // Iniciales para avatar
                const initials = data.user.nombre.split(' ')
                    .map(n => n[0])
                    .join('')
                    .toUpperCase()
                    .substring(0, 2);
                document.getElementById('userAvatar').textContent = initials;
                
                // Mostrar app
                document.getElementById('loadingScreen').style.display = 'none';
                document.getElementById('mainApp').style.display = 'block';
                
                // Verificar estado de fichaje
                checkStatus();
                
                // Actualizar reloj cada segundo
                updateClock();
                setInterval(updateClock, 1000);
                
            } catch (error) {
                console.error('Error verificando sesión:', error);
                window.location.href = 'login.html';
            }
        };

        async function checkStatus() {
            const statusDiv = document.getElementById('status');
            const btnClockIn = document.getElementById('btnClockIn');
            const btnClockOut = document.getElementById('btnClockOut');

            try {
                const response = await fetch('api.php?action=get_status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ personId: currentUser.person_id })
                });

                const data = await response.json();

                if (data.status === 'in') {
                    statusDiv.className = 'status in';
                    statusDiv.innerHTML = '✅ <strong>FICHADO</strong> - ' + data.message;
                    btnClockIn.disabled = true;
                    btnClockOut.disabled = false;
                } else {
                    statusDiv.className = 'status out';
                    statusDiv.innerHTML = '⭕ <strong>NO FICHADO</strong> - ' + data.message;
                    btnClockIn.disabled = false;
                    btnClockOut.disabled = true;
                }
            } catch (error) {
                statusDiv.className = 'status';
                statusDiv.innerHTML = '⚠️ Error al verificar estado';
            }
        }

        async function clockIn() {
            await doFichaje('api.php?action=clock_in', 'Entrada', 'btnClockIn');
        }

        async function clockOut() {
            await doFichaje('api.php?action=clock_out', 'Salida', 'btnClockOut');
        }

        async function doFichaje(endpoint, tipo, btnId) {
            const resultDiv = document.getElementById('result');
            const btnClockIn = document.getElementById('btnClockIn');
            const btnClockOut = document.getElementById('btnClockOut');

            // Guardar textos originales
            const originalTextIn = btnClockIn.textContent;
            const originalTextOut = btnClockOut.textContent;

            btnClockIn.disabled = true;
            btnClockOut.disabled = true;

            resultDiv.className = 'result';
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = `⏳ Procesando fichaje de ${tipo.toLowerCase()}...`;

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ personId: currentUser.person_id })
                });

                const data = await response.json();

                if (data.success) {
                    resultDiv.className = 'result success';
                    resultDiv.innerHTML = `
                        ✅ <strong>¡Fichaje de ${tipo} exitoso!</strong><br>
                        <small>Actualizando estado...</small>
                    `;
                    
                    // Restaurar textos originales de botones
                    btnClockIn.textContent = originalTextIn;
                    btnClockOut.textContent = originalTextOut;
                    
                    // Actualizar estado inmediatamente (con retries)
                    let retries = 0;
                    const maxRetries = 5;
                    
                    const updateStatus = async () => {
                        await checkStatus();
                        retries++;
                        
                        // Si el estado aún no cambió y tenemos más intentos, reintentar
                        const statusDiv = document.getElementById('status');
                        const expectedStatus = tipo === 'Entrada' ? 'FICHADO' : 'NO FICHADO';
                        
                        if (!statusDiv.textContent.includes(expectedStatus) && retries < maxRetries) {
                            setTimeout(updateStatus, 1000); // Reintentar cada segundo
                        } else {
                            resultDiv.innerHTML = `
                                ✅ <strong>¡Fichaje de ${tipo} exitoso!</strong><br>
                                <small>${new Date().toLocaleString('es-ES')}</small>
                            `;
                            setTimeout(() => {
                                resultDiv.style.display = 'none';
                            }, 2000);
                        }
                    };
                    
                    updateStatus();
                } else {
                    resultDiv.className = 'result error';
                    resultDiv.innerHTML = `
                        ❌ <strong>Error en el fichaje</strong><br>
                        ${data.error || data.message || 'Error desconocido'}
                    `;
                    
                    // Restaurar textos originales de botones
                    btnClockIn.textContent = originalTextIn;
                    btnClockOut.textContent = originalTextOut;
                    
                    setTimeout(() => {
                        checkStatus();
                    }, 3000);
                }
            } catch (error) {
                resultDiv.className = 'result error';
                resultDiv.innerHTML = `❌ <strong>Error de conexión</strong><br>${error.message}`;
                
                // Restaurar textos originales de botones
                btnClockIn.textContent = originalTextIn;
                btnClockOut.textContent = originalTextOut;
                
                setTimeout(() => {
                    checkStatus();
                }, 3000);
            }
        }

        async function logout() {
            if (confirm('¿Seguro que quieres cerrar sesión?')) {
                try {
                    await fetch('api.php?action=logout');
                    window.location.href = 'login.html';
                } catch (error) {
                    window.location.href = 'login.html';
                }
            }
        }

        function updateClock() {
            const now = new Date();
            const timeStr = now.toLocaleString('es-ES', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('currentTime').textContent = timeStr;
        }
    </script>
</body>
</html>