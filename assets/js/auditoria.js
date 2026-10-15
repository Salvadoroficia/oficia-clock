/* ============================================================
   Oficia Clock · Historial de cambios de fichajes (auditoría)
   - Admin: pide el MOTIVO al editar / borrar / añadir fichajes,
            botón 🕓 en cada fichaje con su historial,
            pantalla de Auditoría con filtros, verificación y exportación.
   - Empleado: lista "Cambios en mi registro" (transparencia).
   Usa los estilos de modal de horarios-calendario.css (.hc-modal).
   Expone un único global: AUD
   ============================================================ */
var AUD = (function () {
    'use strict';

    var API = 'api.php';
    var fetchOriginal = window.fetch.bind(window);
    var S = { usuarios: null, filtros: { tipo: 'cambios', usuario_id: 'all', desde: '', hasta: '', pagina: 1 }, motivoCache: {} };

    // Acciones que el servidor exige con motivo y cómo se le pregunta al admin
    var PIDE_MOTIVO = {
        edit_fichaje: { titulo: '✏️ Motivo de la corrección', chips: ['Olvidó fichar a su hora', 'Error del terminal o de la app', 'Corrección solicitada por el empleado', 'Ajuste autorizado por el responsable'] },
        delete_fichaje: { titulo: '🗑️ Motivo del borrado', chips: ['Fichaje duplicado', 'Fichaje de prueba', 'Fichado con la tarjeta de otra persona', 'Fichaje erróneo'] },
        add_fichaje_manual: { titulo: '➕ Motivo del fichaje manual', chips: ['Olvidó fichar la entrada', 'Olvidó fichar la salida', 'Sin cobertura / app no disponible', 'Trabajo fuera del centro'] },
        delete_fichajes_rango: { titulo: '🗑️ Motivo del borrado por rango', chips: ['Limpieza de datos de prueba', 'Datos importados por error'] },
        delete_fichajes_rango_jibble: { titulo: '🗑️ Motivo del borrado por rango', chips: ['Limpieza de datos de prueba', 'Datos importados por error'] },
        execute_bulk_delete: { titulo: '🗑️ Motivo del borrado en bloque', chips: ['Limpieza de datos de prueba', 'Fichajes duplicados', 'Datos importados por error'] }
    };

    var CAMPOS = { fecha_hora: 'Fecha y hora', tipo: 'Tipo', usuario_id: 'Empleado', nota: 'Nota', dispositivo: 'Dispositivo', es_descanso: 'Descanso' };

    // ---------------------------------------------------------------- utilidades
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(msg, tipo) {
        if (typeof window.mostrarToast === 'function') window.mostrarToast(msg, tipo || 'info');
        else alert(msg);
    }
    function post(action, body) {
        return fetchOriginal(API + '?action=' + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {})
        }).then(function (r) { return r.json(); })
          .catch(function (e) { return { success: false, error: 'Error de conexión: ' + e.message }; });
    }
    function fFecha(s, conSeg) {
        if (!s) return '—';
        var d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return s;
        return d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' '
             + d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', second: conSeg ? '2-digit' : undefined });
    }
    function tipoTxt(t) { return t === 'In' ? 'Entrada' : t === 'Out' ? 'Salida' : (t || '—'); }
    function valor(campo, v, nombre) {
        if (v == null || v === '') return '—';
        if (campo === 'tipo') return tipoTxt(v);
        if (campo === 'fecha_hora') return fFecha(v);
        if (campo === 'es_descanso') return +v ? 'Sí' : 'No';
        if (campo === 'usuario_id') return nombre || ('#' + v);
        return String(v);
    }
    function modal(id, extra) {
        var el = document.getElementById(id);
        if (!el) {
            el = document.createElement('div');
            el.id = id;
            el.className = 'modal-overlay hc-modal aud-modal ' + (extra || '');
            el.innerHTML = '<div class="modal-content"></div>';
            el.addEventListener('click', function (e) { if (e.target === el) cerrar(id); });
            document.body.appendChild(el);
        }
        return el.querySelector('.modal-content');
    }
    function abrir(id) { document.getElementById(id).classList.add('active'); }
    function cerrar(id) { var el = document.getElementById(id); if (el) el.classList.remove('active'); }

    var ACC = {
        INSERT: { txt: 'Alta', cls: 'ins' },
        UPDATE: { txt: 'Modificado', cls: 'upd' },
        DELETE: { txt: 'Borrado', cls: 'del' }
    };
    function badge(acc, r) {
        var a = ACC[acc] || { txt: acc, cls: '' };
        var txt = a.txt;
        if (acc === 'INSERT' && r && r.actor_tipo === 'admin') txt = 'Añadido a mano';
        if (r && r.origen === 'auditoria_restaurar') txt = 'Restaurado';
        return '<span class="aud-badge aud-' + a.cls + '">' + txt + '</span>';
    }
    // Resumen de una línea: "Entrada 14/10/2026 08:00 → 08:30"
    function resumen(r) {
        var a = r.antes || {}, d = r.despues || {}, base = r.accion === 'DELETE' ? a : d;
        var txt = tipoTxt(base.tipo) + ' del ' + fFecha(base.fecha_hora);
        if (r.accion === 'UPDATE') {
            var cambios = Object.keys(CAMPOS).filter(function (k) { return String(a[k]) !== String(d[k]); });
            if (cambios.indexOf('fecha_hora') >= 0) txt = tipoTxt(d.tipo) + ': ' + fFecha(a.fecha_hora) + ' → ' + fFecha(d.fecha_hora);
            else txt += ' · cambia ' + cambios.map(function (k) { return CAMPOS[k].toLowerCase(); }).join(', ');
        }
        return txt;
    }
    function diffHTML(r) {
        var a = r.antes || {}, d = r.despues || {};
        var filas = Object.keys(CAMPOS).map(function (k) {
            var va = valor(k, a[k], a.nombre), vd = valor(k, d[k], d.nombre);
            if (r.accion === 'UPDATE' && va === vd) return '';
            if (r.accion === 'INSERT') return '<tr><td>' + CAMPOS[k] + '</td><td colspan="2"><b>' + esc(vd) + '</b></td></tr>';
            if (r.accion === 'DELETE') return '<tr><td>' + CAMPOS[k] + '</td><td colspan="2"><s>' + esc(va) + '</s></td></tr>';
            return '<tr><td>' + CAMPOS[k] + '</td><td><s>' + esc(va) + '</s></td><td><b>' + esc(vd) + '</b></td></tr>';
        }).join('');
        return '<table class="aud-diff">' + filas + '</table>';
    }

    // ---------------------------------------------------------------- motivo
    function pedirMotivo(cfg) {
        return new Promise(function (resolve) {
            var carga = document.getElementById('loading-overlay');
            if (carga) carga.style.display = 'none';           // el overlay de "Guardando…" tapa el modal
            var c = modal('audModalMotivo', 'hc-modal-sm aud-top');
            c.innerHTML = '<h2>' + esc(cfg.titulo) + '</h2>'
                + '<div class="hc-sub">Queda guardado en el historial junto con quién lo hace, cuándo y los datos anteriores. El empleado podrá verlo.</div>'
                + '<div class="aud-chips">' + cfg.chips.map(function (t) { return '<button type="button" class="hc-btn mini">' + esc(t) + '</button>'; }).join('') + '</div>'
                + '<textarea class="hc-input aud-motivo" id="audMotivoTxt" rows="3" maxlength="500" placeholder="Escribe el motivo…"></textarea>'
                + '<div class="modal-buttons">'
                + '<button type="button" class="btn-cancel" id="audMotivoNo">Cancelar</button>'
                + '<button type="button" class="hc-btn primary" id="audMotivoSi">💾 Continuar</button></div>';
            var ta = c.querySelector('#audMotivoTxt');
            Array.prototype.forEach.call(c.querySelectorAll('.aud-chips button'), function (b) {
                b.onclick = function () { ta.value = b.textContent; ta.focus(); };
            });
            function fin(v) {
                cerrar('audModalMotivo');
                if (carga) carga.style.display = v ? 'flex' : 'none';
                if (!v && carga) carga.remove();
                resolve(v);
            }
            c.querySelector('#audMotivoNo').onclick = function () { fin(null); };
            c.querySelector('#audMotivoSi').onclick = function () {
                var v = ta.value.trim();
                if (v.length < 3) { ta.classList.add('aud-err'); ta.focus(); return; }
                fin(v);
            };
            abrir('audModalMotivo');
            setTimeout(function () { ta.focus(); }, 50);
        });
    }

    // Intercepta las llamadas del panel que modifican fichajes y les añade el motivo
    function instalarInterceptor() {
        if (window.__audFetch) return;
        window.__audFetch = true;
        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            var m = /[?&]action=([a-z_]+)/.exec(url);
            var cfg = m && PIDE_MOTIVO[m[1]];
            if (!cfg || !init || typeof init.body !== 'string') return fetchOriginal(input, init);
            var body;
            try { body = JSON.parse(init.body || '{}'); } catch (e) { return fetchOriginal(input, init); }
            if (body.motivo) return fetchOriginal(input, init);

            // La edición con conflicto se reenvía con la entrada/salida elegida: no volver a preguntar
            var clave = m[1] + ':' + (body.fichaje_id || '');
            var cache = S.motivoCache[clave];
            var pedir = cache && Date.now() - cache.t < 120000 ? Promise.resolve(cache.v) : pedirMotivo(cfg);
            return pedir.then(function (motivo) {
                if (!motivo) {
                    return new Response(JSON.stringify({ success: false, error: 'Cancelado: hay que indicar el motivo del cambio.' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
                }
                S.motivoCache[clave] = { v: motivo, t: Date.now() };
                body.motivo = motivo;
                if (m[1] === 'add_fichaje_manual' && (!body.nota || body.nota === 'Fichaje manual por admin')) body.nota = 'Manual: ' + motivo;
                return fetchOriginal(input, Object.assign({}, init, { body: JSON.stringify(body) }));
            });
        };
    }

    // ---------------------------------------------------------------- 🕓 en cada fichaje
    var _timer = null;
    function programarBotones() { clearTimeout(_timer); _timer = setTimeout(ponerBotones, 150); }
    function ponerBotones() {
        var nuevos = [];
        Array.prototype.forEach.call(document.querySelectorAll('button[onclick^="abrirModalEditar("]:not([data-aud])'), function (b) {
            var m = /abrirModalEditar\((\d+)/.exec(b.getAttribute('onclick'));
            b.setAttribute('data-aud', '1');
            if (!m) return;
            var id = +m[1];
            var h = document.createElement('button');
            h.type = 'button';
            h.className = (b.classList.contains('btn-edit-fichaje') ? 'btn-edit-fichaje ' : '') + 'aud-hist';
            h.setAttribute('data-aud-id', id);
            h.title = 'Historial de cambios';
            h.innerHTML = b.classList.contains('btn-edit-fichaje') ? '🕓' : '🕓 Historial';
            h.onclick = function () { verFichaje(id); };
            b.parentNode.insertBefore(h, b.nextSibling);
            nuevos.push(id);
        });
        if (!nuevos.length) return;
        post('auditoria_marcas', { ids: nuevos }).then(function (d) {
            if (!d.success) return;
            Object.keys(d.marcas || {}).forEach(function (id) {
                var mk = d.marcas[id];
                Array.prototype.forEach.call(document.querySelectorAll('.aud-hist[data-aud-id="' + id + '"]'), function (h) {
                    h.classList.add('aud-mod');
                    h.title = [mk.manual ? 'Añadido a mano' : '', mk.ediciones ? 'Modificado ' + mk.ediciones + (mk.ediciones > 1 ? ' veces' : ' vez') : ''].filter(Boolean).join(' · ') + ' — ver historial';
                });
            });
        });
    }

    // ---------------------------------------------------------------- historial de un fichaje
    function verFichaje(id) {
        var c = modal('audModalFichaje');
        c.innerHTML = '<h2>🕓 Historial del fichaje #' + id + '</h2><div class="hc-sub">Cargando…</div>';
        abrir('audModalFichaje');
        post('auditoria_fichaje', { fichaje_id: id }).then(function (d) {
            if (!d.success) {
                c.innerHTML = '<h2>🕓 Historial del fichaje #' + id + '</h2>' + avisoNoInstalado(d) + botonesCerrar('audModalFichaje');
                return;
            }
            var lista = d.historial.length
                ? '<div class="aud-timeline">' + d.historial.map(function (r) { return itemHTML(r, !d.existe); }).join('') + '</div>'
                : '<div class="hc-vacio">Este fichaje es anterior a la activación del historial: no hay cambios registrados.</div>';
            c.innerHTML = '<h2>🕓 Historial del fichaje #' + id + '</h2>'
                + (d.existe ? '' : '<div class="hc-banner bloq">Este fichaje está borrado.</div>')
                + lista + botonesCerrar('audModalFichaje');
        });
    }

    function itemHTML(r, restaurable) {
        return '<div class="aud-item aud-it-' + (ACC[r.accion] || {}).cls + '">'
            + '<div class="aud-item-cab">' + badge(r.accion, r) + ' <b>' + esc(fFecha(r.fecha, true)) + '</b>'
            + '<span class="aud-quien">' + esc(r.actor) + (r.ip ? ' · ' + esc(r.ip) : '') + '</span></div>'
            + (r.motivo ? '<div class="aud-motivo-txt">📝 ' + esc(r.motivo) + '</div>' : '')
            + diffHTML(r)
            + (restaurable && r.accion === 'DELETE' ? '<button type="button" class="hc-btn mini" onclick="AUD.restaurar(' + r.id + ')">↩️ Restaurar este fichaje</button>' : '')
            + '</div>';
    }
    function botonesCerrar(id) {
        return '<div class="modal-buttons"><button type="button" class="btn-cancel" onclick="AUD.cerrar(\'' + id + '\')">Cerrar</button></div>';
    }
    function avisoNoInstalado(d) {
        if (d && d.no_instalado) {
            return '<div class="hc-banner bloq"><span>El historial de cambios aún no está activado en esta empresa.</span>'
                + '<button type="button" class="hc-btn primary" onclick="AUD.instalar()">🔒 Activar ahora</button></div>';
        }
        return '<div class="hc-banner bloq">' + esc((d && d.error) || 'Error') + '</div>';
    }

    function restaurar(hid) {
        pedirMotivo({ titulo: '↩️ Motivo de la restauración', chips: ['Borrado por error', 'Corrección de un borrado anterior'] }).then(function (motivo) {
            if (!motivo) return;
            post('auditoria_restaurar', { historial_id: hid, motivo: motivo }).then(function (d) {
                if (!d.success) return toast('❌ ' + d.error, 'error');
                toast('✅ ' + d.message, 'success');
                cerrar('audModalFichaje');
                if (document.getElementById('audModalPanel') && document.getElementById('audModalPanel').classList.contains('active')) cargarListado();
                if (typeof window.loadFichajes === 'function' && document.getElementById('fichajesCard') && document.getElementById('fichajesCard').style.display !== 'none') window.loadFichajes();
            });
        });
    }

    function instalar() {
        if (!confirm('¿Activar el historial de cambios de fichajes?\n\nA partir de ahora se registrará cualquier alta, modificación o borrado de fichajes, con quién lo hace y el motivo.')) return;
        post('auditoria_instalar').then(function (d) {
            if (!d.success) return alert('❌ ' + d.error);
            toast('✅ ' + d.message, 'success');
            abrirAuditoria();
        });
    }

    // ---------------------------------------------------------------- pantalla de auditoría (admin)
    function abrirAuditoria() {
        var c = modal('audModalPanel', 'aud-panel');
        c.innerHTML = '<h2>🕓 Historial de cambios</h2><div class="hc-sub">Cargando…</div>';
        abrir('audModalPanel');
        var pUsuarios = S.usuarios ? Promise.resolve() : fetchOriginal(API + '?action=get_users', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); }).then(function (d) { S.usuarios = d.success ? d.users : []; }).catch(function () { S.usuarios = []; });
        Promise.all([post('auditoria_estado'), pUsuarios]).then(function (res) {
            var st = res[0];
            if (!st.success) { c.innerHTML = '<h2>🕓 Historial de cambios</h2>' + avisoNoInstalado(st) + botonesCerrar('audModalPanel'); return; }
            if (!st.instalado) { c.innerHTML = '<h2>🕓 Historial de cambios</h2>' + avisoNoInstalado({ no_instalado: true }) + botonesCerrar('audModalPanel'); return; }
            var F = S.filtros;
            c.innerHTML = '<h2>🕓 Historial de cambios</h2>'
                + '<div class="hc-sub">Cada alta, modificación o borrado de fichajes queda registrado con quién, cuándo, desde dónde y por qué. '
                + 'Activo desde el ' + esc(fFecha(st.desde)) + ' · ' + st.total + ' registros.</div>'
                + '<div class="hc-toolbar aud-filtros">'
                + '<select class="hc-select" id="audFUsuario"><option value="all">👥 Todos</option>'
                + (S.usuarios || []).map(function (u) { return '<option value="' + u.id + '"' + (String(F.usuario_id) === String(u.id) ? ' selected' : '') + '>' + esc(u.nombre) + '</option>'; }).join('') + '</select>'
                + '<select class="hc-select" id="audFTipo"><option value="cambios"' + (F.tipo === 'cambios' ? ' selected' : '') + '>Solo cambios manuales</option><option value="todo"' + (F.tipo === 'todo' ? ' selected' : '') + '>Todo (incluye fichajes normales)</option></select>'
                + '<input type="date" class="hc-input" id="audFDesde" value="' + esc(F.desde) + '" title="Desde">'
                + '<input type="date" class="hc-input" id="audFHasta" value="' + esc(F.hasta) + '" title="Hasta">'
                + '</div>'
                + '<div class="hc-toolbar" style="margin-top:-4px">'
                + '<button type="button" class="hc-btn" onclick="AUD.verificar()">🔒 Verificar integridad</button>'
                + '<button type="button" class="hc-btn" onclick="AUD.exportar()">📄 Exportar CSV</button>'
                + '<span id="audVerif" class="aud-verif"></span></div>'
                + '<div id="audLista"></div>'
                + botonesCerrar('audModalPanel');
            ['audFUsuario', 'audFTipo', 'audFDesde', 'audFHasta'].forEach(function (id) {
                document.getElementById(id).onchange = function () {
                    F.usuario_id = document.getElementById('audFUsuario').value;
                    F.tipo = document.getElementById('audFTipo').value;
                    F.desde = document.getElementById('audFDesde').value;
                    F.hasta = document.getElementById('audFHasta').value;
                    F.pagina = 1;
                    cargarListado();
                };
            });
            cargarListado();
        });
    }

    function cargarListado() {
        var box = document.getElementById('audLista');
        if (!box) return;
        box.innerHTML = '<div class="hc-vacio">Cargando…</div>';
        post('auditoria_listado', S.filtros).then(function (d) {
            if (!d.success) { box.innerHTML = avisoNoInstalado(d); return; }
            S.ultimo = d.registros;
            if (!d.registros.length) {
                box.innerHTML = '<div class="hc-vacio" style="padding:14px 0">No hay cambios con estos filtros. 👍</div>';
                return;
            }
            box.innerHTML = '<div class="aud-tabla">' + d.registros.map(function (r) {
                return '<div class="aud-fila" onclick="AUD.verFichaje(' + r.fichaje_id + ')" title="Ver el historial completo de este fichaje">'
                    + '<div class="aud-f-cuando">' + esc(fFecha(r.fecha)) + '</div>'
                    + '<div class="aud-f-que">' + badge(r.accion, r) + ' <b>' + esc(r.empleado || ('#' + r.usuario_id)) + '</b> · ' + esc(resumen(r))
                    + (r.motivo ? '<div class="aud-motivo-txt">📝 ' + esc(r.motivo) + '</div>' : '') + '</div>'
                    + '<div class="aud-f-quien">' + esc(r.actor) + '</div>'
                    + '</div>';
            }).join('') + '</div>'
                + (d.paginas > 1 ? '<div class="aud-pag">'
                    + '<button type="button" class="hc-btn mini" ' + (d.pagina <= 1 ? 'disabled' : '') + ' onclick="AUD._pag(-1)">‹ Anteriores</button>'
                    + '<span>Página ' + d.pagina + ' de ' + d.paginas + ' · ' + d.total + ' registros</span>'
                    + '<button type="button" class="hc-btn mini" ' + (d.pagina >= d.paginas ? 'disabled' : '') + ' onclick="AUD._pag(1)">Siguientes ›</button></div>' : '');
        });
    }

    function verificar() {
        var box = document.getElementById('audVerif');
        if (box) { box.className = 'aud-verif'; box.textContent = 'Verificando…'; }
        post('auditoria_verificar').then(function (d) {
            if (!box) return;
            if (!d.success) { box.textContent = '❌ ' + d.error; return; }
            box.className = 'aud-verif ' + (d.integro ? 'ok' : 'ko');
            box.textContent = d.message;
            if (!d.integro) box.title = d.errores.map(function (e) { return (e.id ? '#' + e.id + ': ' : '') + e.problema; }).join('\n');
        });
    }

    // Exporta todo el resultado del filtro actual (todas las páginas)
    function exportar() {
        var filtro = Object.assign({}, S.filtros), todas = [];
        function pagina(n) {
            filtro.pagina = n;
            return post('auditoria_listado', filtro).then(function (d) {
                if (!d.success) throw new Error(d.error);
                todas = todas.concat(d.registros);
                if (n < d.paginas && n < 200) return pagina(n + 1);
            });
        }
        pagina(1).then(function () {
            var q = function (v) { return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; };
            var csv = ['Fecha cambio;Acción;Empleado;Fichaje;Antes;Después;Hecho por;IP;Origen;Motivo'].concat(todas.map(function (r) {
                var a = r.antes || {}, d = r.despues || {};
                return [fFecha(r.fecha, true), (ACC[r.accion] || {}).txt || r.accion, r.empleado || '', '#' + r.fichaje_id,
                    r.antes ? tipoTxt(a.tipo) + ' ' + fFecha(a.fecha_hora) : '', r.despues ? tipoTxt(d.tipo) + ' ' + fFecha(d.fecha_hora) : '',
                    r.actor, r.ip || '', r.origen, r.motivo || ''].map(q).join(';');
            })).join('\r\n');
            var blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'historial_fichajes_' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a); a.click(); a.remove();
        }).catch(function (e) { toast('❌ ' + e.message, 'error'); });
    }

    // ---------------------------------------------------------------- empleado
    function initEmpleado(sel) {
        var host = typeof sel === 'string' ? document.querySelector(sel) : sel;
        if (!host) return;
        host.innerHTML = '<div class="hc-wrap aud-emp"><h3>📝 Cambios en mi registro</h3><div class="hc-vacio">Cargando…</div></div>';
        post('mis_cambios_fichajes').then(function (d) {
            var cuerpo;
            if (!d.success) cuerpo = '<div class="hc-vacio">' + esc(d.error) + '</div>';
            else if (!d.cambios.length) cuerpo = '<div class="aud-emp-ok">✅ La empresa no ha modificado ninguno de tus fichajes.</div>';
            else cuerpo = '<div class="hc-sub" style="margin:0 0 8px">Por ley tienes derecho a conocer cualquier corrección de tu registro de jornada.</div>'
                + d.cambios.map(function (r, i) {
                    return '<div class="aud-emp-item"' + (i >= 5 ? ' hidden' : '') + '>'
                        + '<div>' + badge(r.accion, r) + ' <b>' + esc(resumen(r)) + '</b></div>'
                        + '<small>' + esc(fFecha(r.fecha)) + ' · ' + esc(r.actor) + '</small>'
                        + (r.motivo ? '<div class="aud-motivo-txt">📝 ' + esc(r.motivo) + '</div>' : '')
                        + '</div>';
                }).join('')
                + (d.cambios.length > 5 ? '<button type="button" class="hc-btn mini" onclick="Array.prototype.forEach.call(this.parentNode.querySelectorAll(\'.aud-emp-item[hidden]\'),function(e){e.hidden=false});this.remove()">Ver los ' + d.cambios.length + ' cambios</button>' : '');
            host.innerHTML = '<div class="hc-wrap aud-emp"><h3>📝 Cambios en mi registro</h3>' + cuerpo + '</div>';
        });
    }

    // ---------------------------------------------------------------- estilos
    function estilos() {
        if (document.getElementById('audEstilos')) return;
        var st = document.createElement('style');
        st.id = 'audEstilos';
        st.textContent = [
            '.aud-modal.aud-top{z-index:100000}',
            '.aud-panel .modal-content{max-width:900px}',
            '.aud-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}',
            '.aud-modal textarea.aud-motivo{width:100%;resize:vertical;font-family:inherit}',
            '.aud-modal textarea.aud-err{border-color:#e5484d}',
            '.aud-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap}',
            '.aud-ins{background:#e0f2fe;color:#0369a1}.aud-upd{background:#fef3c7;color:#92400e}.aud-del{background:#fee2e2;color:#b91c1c}',
            '.aud-timeline{display:grid;gap:10px;margin-bottom:6px}',
            '.aud-item{border:1px solid var(--hc-border);border-left:4px solid #94a3b8;border-radius:10px;padding:10px 12px;background:var(--hc-soft)}',
            '.aud-it-ins{border-left-color:#0ea5e9}.aud-it-upd{border-left-color:#f59e0b}.aud-it-del{border-left-color:#e5484d}',
            '.aud-item-cab{display:flex;flex-wrap:wrap;align-items:center;gap:8px;font-size:13px}',
            '.aud-quien{margin-left:auto;color:var(--hc-sub);font-size:12px}',
            '.aud-motivo-txt{font-size:13px;margin:6px 0 2px;color:var(--hc-text)}',
            '.aud-diff{width:100%;border-collapse:collapse;margin:6px 0;font-size:12px;display:table !important}',
            '.aud-diff td{padding:3px 6px !important;border-bottom:1px solid var(--hc-border);background:transparent !important;position:static !important}',
            '.aud-diff td:first-child{color:var(--hc-sub);width:110px}',
            '.aud-diff s{color:#b91c1c}',
            '.aud-tabla{display:grid;border:1px solid var(--hc-border);border-radius:12px;overflow:hidden}',
            '.aud-fila{display:grid;grid-template-columns:130px 1fr 170px;gap:10px;padding:9px 12px;border-top:1px solid var(--hc-border);cursor:pointer;font-size:13px;align-items:start}',
            '.aud-fila:first-child{border-top:none}.aud-fila:hover{background:var(--hc-soft)}',
            '.aud-f-cuando{color:var(--hc-sub);font-variant-numeric:tabular-nums}',
            '.aud-f-quien{color:var(--hc-sub);font-size:12px;text-align:right}',
            '.aud-pag{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:10px;font-size:12px;color:var(--hc-sub)}',
            '.aud-verif{font-size:13px;font-weight:700}.aud-verif.ok{color:#15803d}.aud-verif.ko{color:#b91c1c}',
            '.aud-filtros .hc-select,.aud-filtros .hc-input{flex:1 1 150px}',
            'button.aud-hist{position:relative}',
            'button.aud-hist.aud-mod::after{content:"";position:absolute;top:2px;right:2px;width:8px;height:8px;border-radius:50%;background:#f59e0b;box-shadow:0 0 0 2px #fff}',
            '.aud-emp h3{font-size:16px;margin:22px 0 10px}',
            '.aud-emp-ok{padding:12px 14px;border-radius:12px;background:var(--hc-soft);border:1px solid var(--hc-border);font-size:14px}',
            '.aud-emp-item{padding:10px 12px;border:1px solid var(--hc-border);border-radius:10px;margin-bottom:8px;background:var(--hc-soft);font-size:14px}',
            '.aud-emp-item small{color:var(--hc-sub);display:block;margin-top:2px}',
            '@media (max-width:640px){.aud-fila{grid-template-columns:1fr}.aud-f-quien{text-align:left}.aud-quien{margin-left:0;width:100%}}'
        ].join('\n');
        document.head.appendChild(st);
    }

    // ---------------------------------------------------------------- arranque
    function initAdmin() {
        estilos();
        instalarInterceptor();
        ponerBotones();
        new MutationObserver(programarBotones).observe(document.body, { childList: true, subtree: true });
    }

    function autoInit() {
        estilos();
        var emp = document.querySelector('[data-aud-empleado]');
        if (emp) initEmpleado(emp);
        if (document.body.classList.contains('page-admin') && document.querySelector('#fichajesCard, #calendarioCard')) initAdmin();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autoInit);
    else autoInit();

    return {
        abrirAuditoria: abrirAuditoria,
        verFichaje: verFichaje,
        restaurar: restaurar,
        instalar: instalar,
        verificar: verificar,
        exportar: exportar,
        initEmpleado: initEmpleado,
        initAdmin: initAdmin,
        cerrar: cerrar,
        _pag: function (n) { S.filtros.pagina += n; cargarListado(); }
    };
})();
