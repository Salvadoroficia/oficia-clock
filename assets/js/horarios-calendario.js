/* ============================================================
   Oficia Clock · Horarios y Calendario (admin + empleado)
   Requiere antes:
     https://cdn.jsdelivr.net/npm/fullcalendar@6.1.21/index.global.min.js
     https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.21/locales/es.global.min.js
   Expone un único global: HC
   ============================================================ */
var HC = (function () {
    'use strict';

    var API = 'api.php';
    var DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    var S = {
        modo: 'admin',          // 'admin' | 'user'
        cal: null,
        calEl: null,
        usuarios: [],           // resumen de usuarios (admin)
        filtro: 'all',
        reales: true,
        editor: { uid: null, nombre: '', tramos: {} },
        excep: { uid: null, fecha: null, tramos: [] }
    };

    // ---------------------------------------------------------------- utilidades
    function post(action, body) {
        var modo = S.modo;
        var payload = Object.assign({}, { scope: modo === 'user' ? 'me' : 'admin' }, body || {});
        return fetch(API + '?action=' + action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.text().then(function (t) {
                if (modo === 'admin' && r.status === 403) { sesionAdminCaducada(); return { success: false, error: 'Sesión de administrador caducada' }; }
                try { return JSON.parse(t); }
                catch (e) { console.error('[HC] Respuesta no JSON de ' + action, t); return { success: false, error: 'Respuesta no válida del servidor' }; }
            });
        }).catch(function (e) { return { success: false, error: 'Error de conexión: ' + e.message }; });
    }

    // El panel de admin perdió la sesión (p. ej. se cerró sesión como empleado en otra pestaña
    // del mismo navegador: comparten sesión PHP y logout la destruye entera)
    var _avisoSesion = false;
    function sesionAdminCaducada() {
        if (_avisoSesion) return;
        _avisoSesion = true;
        alert('⚠️ Tu sesión de administrador ha caducado.\n\nSuele pasar si has cerrado sesión como empleado en este mismo navegador. Vuelve a iniciar sesión como administrador.');
        window.location.href = 'admin-login.html';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function toast(msg, tipo) {
        if (typeof window.mostrarToast === 'function') window.mostrarToast(msg, tipo || 'info');
        else alert(msg);
    }

    function fmtMin(m) {
        m = Math.round(m || 0);
        var h = Math.floor(m / 60), r = m % 60;
        return h + 'h' + (r ? ' ' + (r < 10 ? '0' : '') + r + 'm' : '');
    }

    function minTramo(i, f) {
        if (!i || !f) return 0;
        var a = +i.slice(0, 2) * 60 + +i.slice(3, 5);
        var b = +f.slice(0, 2) * 60 + +f.slice(3, 5);
        if (b <= a) b += 1440;
        return b - a;
    }

    function fechaLarga(iso) {
        var d = new Date(iso + 'T12:00:00');
        return d.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }

    function hora(d) {
        return d ? d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }) : '';
    }

    // Crea (una vez) un modal con las clases del tema: .modal-overlay + .active
    function modal(id, extraClass) {
        var el = document.getElementById(id);
        if (!el) {
            el = document.createElement('div');
            el.id = id;
            el.className = 'modal-overlay hc-modal ' + (extraClass || '');
            el.innerHTML = '<div class="modal-content"></div>';
            el.addEventListener('click', function (e) { if (e.target === el) cerrar(id); });
            document.body.appendChild(el);
        }
        return el.querySelector('.modal-content');
    }
    function abrir(id) { document.getElementById(id).classList.add('active'); }
    function cerrar(id) { var el = document.getElementById(id); if (el) el.classList.remove('active'); }

    // ================================================================ CALENDARIO
    function montarCalendario(el) {
        if (typeof FullCalendar === 'undefined') {
            el.innerHTML = '<div class="result error">No se ha cargado FullCalendar. Revisa las etiquetas &lt;script&gt; del &lt;head&gt;.</div>';
            return null;
        }
        S.compacto = esCompacto(el);
        el.classList.toggle('hc-compacto', S.compacto);
        var cal = new FullCalendar.Calendar(el, Object.assign({
            locale: 'es',
            firstDay: 1,
            initialView: S.compacto ? 'listWeek' : 'timeGridWeek',
            buttonText: { today: 'Hoy', month: 'Mes', week: 'Semana', list: 'Lista' },
            height: S.compacto ? 'auto' : 720,
            views: {
                dayGridMonth: { dayMaxEvents: 4 },
                listWeek: { noEventsContent: 'Sin turnos ni ausencias esta semana' },
                // Semana para pantallas estrechas: una columna por día con fichas apiladas
                semanaCompacta: { type: 'dayGrid', duration: { weeks: 1 }, buttonText: 'Semana', dayHeaderFormat: { weekday: 'short', day: 'numeric' } }
            },
            // Mes y Lista crecen con su contenido (sin scroll interno); Semana mantiene 720px
            datesSet: function (info) {
                var h = (S.compacto || info.view.type !== 'timeGridWeek') ? 'auto' : 720;
                if (info.view.calendar.getOption('height') !== h) info.view.calendar.setOption('height', h);
                setTimeout(pintarCompacto, 0);
            },
            eventsSet: function () { setTimeout(pintarCompacto, 0); },
            windowResize: function () { ajustarCompacto(); },
            nowIndicator: true,
            allDayText: 'Día',
            slotMinTime: '00:00:00',
            slotMaxTime: '24:00:00',
            scrollTime: '07:00:00',
            slotDuration: '00:30:00',
            eventMaxStack: 4,
            slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            events: cargarEventos,
            eventClick: function (info) { info.jsEvent.preventDefault(); detalleEvento(info.event); },
            dateClick: function (info) {
                if (S.compacto && vistaCompacta(info.view.type)) { mostrarDia(info.dateStr.slice(0, 10)); return; }
                if (S.modo !== 'admin') return;
                abrirExcepcion(S.filtro === 'all' ? null : +S.filtro, info.dateStr.slice(0, 10));
            },
            eventDidMount: function (info) {
                var p = info.event.extendedProps, t = info.event.title;
                if (info.event.start && !info.event.allDay) t += ' · ' + hora(info.event.start) + '–' + hora(info.event.end);
                if (p.motivo) t += ' · ' + p.motivo;
                info.el.setAttribute('title', t);
            }
        }, opcionesBarra(S.compacto)));
        cal.render();
        return cal;
    }
 
    // MODO COMPACTO (móvil / pantallas estrechas)
    // Mes: un punto de color por persona y día. Semana: fichas con iniciales y horas.
    // Al tocar un día aparece debajo el detalle de ese día.
    function esCompacto(el) { return (el.clientWidth || window.innerWidth) < 640; }
    function vistaCompacta(tipo) { return tipo === 'dayGridMonth' || tipo === 'semanaCompacta'; }
 
    function opcionesBarra(compacto) {
        return compacto
            ? { headerToolbar: { left: 'prev,next', center: 'title', right: 'today' },
                footerToolbar: { center: 'dayGridMonth,semanaCompacta,listWeek' } }
            : { headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listWeek' },
                footerToolbar: false };
    }
 
    // Si cambia el ancho (girar el móvil, redimensionar ventana) pasa de un modo a otro
    function ajustarCompacto() {
        if (!S.cal || !S.calEl) return;
        var c = esCompacto(S.calEl);
        if (c === S.compacto) { pintarCompacto(); return; }
        S.compacto = c;
        S.calEl.classList.toggle('hc-compacto', c);
        var o = opcionesBarra(c);
        S.cal.setOption('headerToolbar', o.headerToolbar);
        S.cal.setOption('footerToolbar', o.footerToolbar);
        var v = S.cal.view.type;
        if (c && v === 'timeGridWeek') S.cal.changeView('semanaCompacta');
        else if (!c && v === 'semanaCompacta') S.cal.changeView('timeGridWeek');
        if (!c) cerrarDia();
        pintarCompacto();
    }
 
    function colorEvento(ev) {
        var p = ev.extendedProps || {};
        if (p.tipo === 'ausencia') {
            return { vacaciones: '#0ea5a4', baja_laboral: '#e5484d', baja: '#e5484d', suspension_indefinida: '#64748b', asuntos_propios: '#8b5cf6' }[p.tipo_ausencia] || '#f59e0b';
        }
        if (p.tipo === 'real') return '#374151';
        if (p.tipo === 'bloqueo') return '#e5484d';
        if (p.tipo === 'libre') return 'transparent';
        return ev.backgroundColor || '#4f7cff';
    }
 
    function horaCorta(d) { return hora(d).replace(/^0(?=\d)/, '').replace(/:00$/, ''); }
 
    function iniciales(nombre) {
        var w = String(nombre || '').trim().split(/\s+/).filter(Boolean);
        if (!w.length) return '?';
        return (w[0][0] + (w[1] ? w[1][0] : '')).toUpperCase();
    }
 
    // Eventos de un día concreto (YYYY-MM-DD), ordenados para mostrar
    function eventosDelDia(fecha) {
        if (!S.cal) return [];
        var ini = new Date(fecha + 'T00:00:00'), fin = new Date(ini); fin.setDate(fin.getDate() + 1);
        var orden = { festivo: 0, ausencia: 1, libre: 2, turno: 3, especial: 3, real: 4, bloqueo: 5 };
        return S.cal.getEvents().filter(function (ev) {
            var p = ev.extendedProps || {};
            if (!ev.allDay && p.fecha) return p.fecha === fecha;           // turnos nocturnos: día en que empiezan
            if (!ev.allDay) return ev.start >= ini && ev.start < fin;
            var e = ev.end || new Date(ev.start.getTime() + 86400000);
            return ev.start < fin && e > ini;
        }).sort(function (a, b) {
            var oa = orden[a.extendedProps.tipo] || 9, ob = orden[b.extendedProps.tipo] || 9;
            return oa !== ob ? oa - ob : (a.start - b.start);
        });
    }
 
    function pintarCompacto() {
        if (!S.cal || !S.calEl) return;
        S.calEl.querySelectorAll('.hc-cmp').forEach(function (n) { n.remove(); });
        var tipo = S.cal.view.type;
        if (!S.compacto || !vistaCompacta(tipo)) return;
        var semana = tipo === 'semanaCompacta';
        S.calEl.querySelectorAll('.fc-daygrid-day[data-date]').forEach(function (td) {
            var fecha = td.getAttribute('data-date');
            var evs = eventosDelDia(fecha).filter(function (ev) { return ev.extendedProps.tipo !== 'festivo'; });
            var box = document.createElement('div');
            box.className = 'hc-cmp ' + (semana ? 'hc-cmp-semana' : 'hc-cmp-mes');
            if (semana) {
                box.innerHTML = evs.map(function (ev) {
                    var p = ev.extendedProps, c = colorEvento(ev);
                    var ico = p.tipo === 'ausencia' ? ev.title.split(' ')[0] : p.tipo === 'libre' ? '🌙' : p.tipo === 'bloqueo' ? '⛔' : p.tipo === 'real' ? '✓' : '';
                    var hor = ev.allDay ? '' : '<small>' + horaCorta(ev.start) + (ev.end ? '–' + horaCorta(ev.end) : '') + '</small>';
                    var cls = 'hc-ficha hc-f-' + p.tipo;
                    return '<div class="' + cls + '" style="--c:' + c + '" title="' + esc(ev.title) + '">'
                        + '<b>' + ico + (S.modo === 'admin' || p.tipo === 'ausencia' ? esc(iniciales(p.nombre)) : '') + '</b>' + hor + '</div>';
                }).join('');
            } else {
                var max = 6, vis = evs.slice(0, max);
                box.innerHTML = vis.map(function (ev) {
                    var p = ev.extendedProps;
                    return '<i class="hc-punto hc-p-' + p.tipo + '" style="--c:' + colorEvento(ev) + '"></i>';
                }).join('') + (evs.length > max ? '<span class="hc-mas">+' + (evs.length - max) + '</span>' : '');
            }
            var frame = td.querySelector('.fc-daygrid-day-frame') || td;
            frame.appendChild(box);
            td.classList.toggle('hc-sel', fecha === S.diaSel);
        });
        // Si el día abierto está en el rango visible, refrescar el panel; si no, abrir hoy (o el primer día)
        var visibles = Array.prototype.map.call(S.calEl.querySelectorAll('.fc-daygrid-day[data-date]:not(.fc-day-other)'), function (td) { return td.getAttribute('data-date'); });
        if (!visibles.length) return;
        var hoy = new Date(); hoy = hoy.getFullYear() + '-' + ('0' + (hoy.getMonth() + 1)).slice(-2) + '-' + ('0' + hoy.getDate()).slice(-2);
        if (visibles.indexOf(S.diaSel) < 0) S.diaSel = visibles.indexOf(hoy) >= 0 ? hoy : visibles[0];
        mostrarDia(S.diaSel, true);
    }
 
    function panelDia() {
        var p = document.getElementById('hcDiaPanel');
        if (!p && S.calEl) {
            p = document.createElement('div');
            p.id = 'hcDiaPanel';
            p.className = 'hc-diap';
            S.calEl.parentNode.insertBefore(p, S.calEl.nextSibling);
        }
        return p;
    }
    function cerrarDia() { var p = document.getElementById('hcDiaPanel'); if (p) p.remove(); }
 
    function mostrarDia(fecha, sinScroll) {
        S.diaSel = fecha;
        S.calEl.querySelectorAll('.fc-daygrid-day.hc-sel').forEach(function (td) { td.classList.remove('hc-sel'); });
        var td = S.calEl.querySelector('.fc-daygrid-day[data-date="' + fecha + '"]');
        if (td) td.classList.add('hc-sel');
        var evs = eventosDelDia(fecha), panel = panelDia();
        S.diaEventos = evs;
        var filas = evs.map(function (ev, i) {
            var p = ev.extendedProps;
            var cuando = p.tipo === 'festivo' ? 'Festivo' : ev.allDay ? 'Todo el día' : hora(ev.start) + ' – ' + hora(ev.end);
            var txt = p.tipo === 'festivo' ? (p.nombre_festivo || ev.title) : ev.title;
            return '<button type="button" class="hc-diap-fila" onclick="HC._verEvento(' + i + ')">'
                + '<i class="hc-punto hc-p-' + p.tipo + '" style="--c:' + (p.tipo === 'festivo' ? '#f59e0b' : colorEvento(ev)) + '"></i>'
                + '<span class="hc-diap-hora">' + esc(cuando) + '</span>'
                + '<span class="hc-diap-txt">' + esc(txt) + '</span></button>';
        }).join('');
        panel.innerHTML = '<div class="hc-diap-cab"><b>' + esc(new Date(fecha + 'T12:00:00').toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' })) + '</b>'
            + (S.modo === 'admin' ? '<button type="button" class="hc-btn" onclick="HC.abrirExcepcion(' + (S.filtro === 'all' ? 'null' : +S.filtro) + ',\'' + fecha + '\')">📌 Cambiar</button>' : '')
            + '</div>'
            + (filas || '<div class="hc-diap-vacio">Sin turnos ni ausencias este día</div>');
        if (!sinScroll && panel.getBoundingClientRect().top > window.innerHeight - 80) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
 

    function cargarEventos(info, ok, ko) {
        post('get_calendario_eventos', {
            start: info.startStr.slice(0, 10),
            end: info.endStr.slice(0, 10),
            usuario_id: S.modo === 'admin' ? S.filtro : undefined,
            mostrar_reales: S.reales
        }).then(function (d) {
            if (!d.success) { toast('Calendario: ' + d.error, 'error'); ko(new Error(d.error)); return; }
            ok(d.events || []);
            pintarResumen(d.resumen || []);
        });
    }

    function pintarResumen(lista) {
        var box = document.getElementById('hcResumen');
        if (!box) return;
        if (!lista.length) { box.innerHTML = ''; return; }
        box.innerHTML = lista.map(function (r) {
            var pct = r.min_plan ? Math.min(100, Math.round(r.min_real / r.min_plan * 100)) : 0;
            var real = S.reales
                ? '<small>Fichado: <b style="display:inline">' + fmtMin(r.min_real) + '</b>' + (r.min_plan ? ' (' + pct + '%)' : '') + '</small>'
                  + '<div class="hc-bar"><div style="width:' + pct + '%"></div></div>'
                : '';
            return '<div class="hc-res-item" style="--c:' + esc(r.color) + '">'
                + '<b>' + esc(S.modo === 'user' ? 'En este periodo' : r.nombre) + '</b>'
                + '<small>Planificado: ' + fmtMin(r.min_plan) + '</small>'
                + (r.dias_ausencia ? '<small>Días de ausencia: ' + r.dias_ausencia + '</small>' : '')
                + real + '</div>';
        }).join('');
    }

    function leyendaHTML() {
        return '<div class="hc-legend">'
            + '<span><i style="background:#4f7cff"></i>Turno</span>'
            + '<span><i style="background:#4f7cff;background-image:repeating-linear-gradient(135deg,rgba(255,255,255,.4) 0 3px,transparent 3px 6px)"></i>Turno especial ★</span>'
            + '<span><i style="background:rgba(17,24,39,.75)"></i>Fichado real</span>'
            + '<span><i style="background:#0ea5a4"></i>Vacaciones</span>'
            + '<span><i style="background:#e5484d"></i>Baja</span>'
            + '<span><i style="background:#f59e0b"></i>Otra ausencia</span>'
            + '<span><i style="border:1.5px dashed #94a3b8"></i>Día libre</span>'
            + '<span><i style="background:rgba(245,158,11,.35)"></i>Festivo</span>'
            + (S.modo === 'admin' ? '<span><i style="background:#e5484d"></i>⛔ Intento bloqueado</span>' : '')
            + '</div>';
    }

    function refrescar() { if (S.cal) S.cal.refetchEvents(); }

    function detalleEvento(ev) {
        var p = ev.extendedProps || {};
        if (p.tipo === 'festivo') return toast('🎉 Festivo: ' + (p.nombre_festivo || ev.title), 'info');
        var c = modal('hcModalDetalle', 'hc-modal-sm');
        var filas = [];
        var tipos = { turno: 'Turno habitual', especial: 'Turno especial (solo este día)', real: 'Tiempo fichado', ausencia: 'Ausencia', libre: 'Día libre', bloqueo: 'Intento de fichaje bloqueado' };
        if (p.nombre && S.modo === 'admin') filas.push(['Empleado', p.nombre]);
        filas.push(['Tipo', tipos[p.tipo] || p.tipo]);
        if (p.tipo === 'ausencia') {
            filas.push(['Clase', (p.tipo_ausencia || '').replace(/_/g, ' ')]);
            filas.push(['Desde', p.desde ? new Date(p.desde + 'T12:00').toLocaleDateString('es-ES') : '—']);
            filas.push(['Hasta', p.hasta ? new Date(p.hasta + 'T12:00').toLocaleDateString('es-ES') : 'Indefinido']);
        } else if (!ev.allDay && ev.start) {
            filas.push(['Fecha', ev.start.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' })]);
            filas.push(['Horario', hora(ev.start) + ' – ' + hora(ev.end) + (p.en_curso ? ' (en curso)' : '')]);
            if (ev.end) filas.push(['Duración', fmtMin((ev.end - ev.start) / 60000)]);
        } else if (ev.start) {
            filas.push(['Fecha', ev.start.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' })]);
        }
        if (p.origen) filas.push(['Origen', p.origen]);
        if (p.motivo) filas.push(['Motivo', p.motivo]);
        if (p.nota) filas.push(['Nota', p.nota]);

        var puedeCambiar = S.modo === 'admin' && p.usuario_id && ['turno', 'especial', 'libre'].indexOf(p.tipo) >= 0;
        c.innerHTML = '<h2>' + esc(ev.title) + '</h2>'
            + filas.map(function (f) { return '<div class="hc-detail-row"><span>' + esc(f[0]) + '</span><span>' + esc(f[1]) + '</span></div>'; }).join('')
            + '<div class="modal-buttons">'
            + (puedeCambiar ? '<button class="hc-btn" id="hcDetCambiar">✏️ Cambiar este día</button>' : '')
            + '<button class="btn-cancel" onclick="HC.cerrar(\'hcModalDetalle\')">Cerrar</button></div>';
        if (puedeCambiar) {
            c.querySelector('#hcDetCambiar').onclick = function () {
                cerrar('hcModalDetalle');
                abrirExcepcion(p.usuario_id, p.fecha || ev.startStr.slice(0, 10));
            };
        }
        abrir('hcModalDetalle');
    }

    // ================================================================ ADMIN · CALENDARIO
    function abrirCalendario() {
        S.modo = 'admin';
        var card = document.getElementById('calendarioCard');
        var host = document.getElementById('hcCalAdmin');
        if (!card || !host) { toast('Falta el bloque #calendarioCard en admin.html', 'error'); return; }
        card.style.display = 'block';

        if (!S.cal) {
            host.innerHTML = '<div class="hc-wrap">'
                + '<div class="hc-toolbar">'
                + '<select class="hc-select hc-grow" id="hcFiltroUsuario"><option value="all">👥 Todos los empleados</option></select>'
                + '<label class="hc-check"><input type="checkbox" id="hcReales" checked> Ver fichajes reales</label>'
                + '<button class="hc-btn" onclick="HC.abrirGestion()">⚙️ Horarios</button>'
                + '<button class="hc-btn" onclick="HC.abrirExcepcion(null, null)">📌 Cambiar un día</button>'
                + '<button class="hc-btn" onclick="HC.cerrarCalendario()">✕</button>'
                + '</div>'
                + '<div class="hc-cal" id="hcCalendario"></div>'
                + leyendaHTML()
                + '<div class="hc-resumen" id="hcResumen"></div>'
                + '</div>';
            document.getElementById('hcFiltroUsuario').onchange = function () { S.filtro = this.value; refrescar(); };
            document.getElementById('hcReales').onchange = function () { S.reales = this.checked; refrescar(); };
            S.calEl = document.getElementById('hcCalendario');
            cargarUsuarios().then(rellenarFiltro);
            setTimeout(function () { S.cal = montarCalendario(S.calEl); }, 30);
        } else {
            setTimeout(function () { S.cal.updateSize(); refrescar(); }, 30);
        }
        setTimeout(function () { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 100);
    }

    function cerrarCalendario() {
        var card = document.getElementById('calendarioCard');
        if (card) card.style.display = 'none';
    }

    function cargarUsuarios() {
        return post('get_horarios_resumen').then(function (d) {
            if (d.success) { S.usuarios = d.usuarios || []; S.config = d.config || {}; }
            else toast('Error cargando usuarios: ' + d.error, 'error');
            return S.usuarios;
        });
    }

    function rellenarFiltro() {
        var sel = document.getElementById('hcFiltroUsuario');
        if (!sel) return;
        var actual = S.filtro;
        sel.innerHTML = '<option value="all">👥 Todos los empleados</option>'
            + S.usuarios.map(function (u) { return '<option value="' + u.id + '">' + esc(u.nombre) + (u.restringir_horario ? ' 🔒' : '') + '</option>'; }).join('');
        sel.value = actual;
    }

    // ================================================================ ADMIN · GESTIÓN (botón en Usuarios)
    function abrirGestion() {
        S.modo = 'admin';
        var c = modal('hcModalGestion');
        c.innerHTML = '<h2>🗓️ Horarios de trabajo</h2><div class="hc-sub">Cargando…</div>';
        abrir('hcModalGestion');
        S.festAno = S.festAno || new Date().getFullYear();
        Promise.all([cargarUsuarios(), post('get_festivos', { ano: S.festAno })]).then(function (res) {
            var d = res[1] || {};
            S.festRegion = d.region;            // null = nunca configurada
            S.regiones = d.regiones || S.regiones || {};
            pintarGestion(d.success ? d.festivos : []);
        });
    }

    function pintarGestion(festivos) {
        var c = modal('hcModalGestion');
        var cfg = S.config || {};
        var filas = S.usuarios.map(function (u) {
            var sinHorario = u.restringir_horario && !u.num_tramos;
            var max = u.horas_semanales_max ? ' / máx ' + u.horas_semanales_max + 'h' : '';
            var exceso = u.horas_semanales_max && u.minutos_semana > u.horas_semanales_max * 60;
            return '<div class="hc-user-row">'
                + '<span class="hc-dot" style="background:' + esc(u.color) + '"></span>'
                + '<div><div class="hc-name">' + esc(u.nombre) + '</div>'
                + '<div class="hc-meta">' + (u.num_tramos ? fmtMin(u.minutos_semana) + '/semana' + max : 'Sin horario')
                + (exceso ? ' · <span class="hc-warn">supera el máximo</span>' : '')
                + (sinHorario ? ' · <span class="hc-warn">⚠️ no podrá fichar</span>' : '') + '</div></div>'
                + '<button class="hc-btn mini hc-edit-btn" onclick="HC.abrirEditor(' + u.id + ')">✏️ Horario</button>'
                + '<div title="Bloquear fichajes fuera de horario"><label class="hc-switch"><input type="checkbox" '
                + (u.restringir_horario ? 'checked ' : '') + 'onchange="HC.toggleRestriccion(' + u.id + ', this.checked, this)"><span></span></label>'
                + '<span class="hc-switch-label">' + (u.restringir_horario ? 'Bloqueo' : 'Libre') + '</span></div>'
                + '</div>';
        }).join('');

        var listaFest = festivos.length
            ? festivos.map(function (f) {
                var auto = f.origen === 'auto';
                return '<div class="hc-detail-row"><span>' + new Date(f.fecha + 'T12:00').toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short' })
                    + '</span><span>' + esc(f.nombre)
                    + (auto ? ' <small class="hc-tag" title="Importado automáticamente">auto</small>' : ' <small class="hc-tag hc-tag-local" title="Añadido a mano">local</small>')
                    + ' <button class="hc-btn mini" onclick="HC.borrarFestivo(' + f.id + ')">🗑️</button></span></div>';
            }).join('')
            : '<div class="hc-vacio">No hay festivos en ' + S.festAno + '.</div>';
        var anoAct = new Date().getFullYear();
        var regSel = S.festRegion == null ? 'ES-MC' : S.festRegion;
        var optsReg = Object.keys(S.regiones || {}).map(function (k) {
            return '<option value="' + esc(k) + '"' + (k === regSel ? ' selected' : '') + '>' + esc(S.regiones[k]) + '</option>';
        }).join('');
        var optsAno = [anoAct, anoAct + 1].map(function (a) {
            return '<option value="' + a + '"' + (a === S.festAno ? ' selected' : '') + '>' + a + '</option>';
        }).join('');

        c.innerHTML = '<h2>🗓️ Horarios de trabajo</h2>'
            + '<div class="hc-sub">Con el interruptor <b>Bloqueo</b> activo, el empleado solo puede fichar <b>entrada</b> dentro de su horario (las salidas nunca se bloquean).</div>'

            + '<div class="hc-section"><h3><span>👥 Empleados</span><span style="display:flex;gap:6px">'
            + '<button class="hc-btn mini" onclick="HC.toggleTodos(true)">🔒 Bloqueo a todos</button>'
            + '<button class="hc-btn mini" onclick="HC.toggleTodos(false)">🔓 Quitar a todos</button></span></h3>'
            + (filas || '<div class="hc-vacio">No hay empleados activos.</div>') + '</div>'

            + '<div class="hc-section"><h3>⚙️ Reglas</h3>'
            + '<div style="display:grid;gap:10px">'
            + '<label class="hc-check">Se puede fichar <input type="number" class="hc-input" id="hcCfgMargen" min="0" max="240" style="width:74px" value="' + (cfg.margen_antes_min != null ? cfg.margen_antes_min : 15) + '"> min antes del turno</label>'
            + '<label class="hc-check"><input type="checkbox" id="hcCfgFest" ' + (cfg.bloquear_festivos ? 'checked' : '') + '> Los festivos no se trabaja (salvo turno especial)</label>'
            + '<label class="hc-check"><input type="checkbox" id="hcCfgEdit" ' + (cfg.usuarios_editan_horario ? 'checked' : '') + '> Los empleados sin bloqueo pueden editar su propio horario</label>'
            + '<div><button class="hc-btn primary" onclick="HC.guardarConfig()">💾 Guardar reglas</button></div>'
            + '</div></div>'

            + '<div class="hc-section"><h3><span>🎉 Festivos</span>'
            + '<select class="hc-select" id="hcFestAno" onchange="HC.cambiarAnoFestivos(this.value)">' + optsAno + '</select></h3>'
            + '<div class="hc-toolbar" style="margin:0 0 10px">'
            + '<select class="hc-select hc-grow" id="hcFestRegion">' + optsReg + '</select>'
            + '<button class="hc-btn" id="hcFestImportar" onclick="HC.importarFestivos()">📥 Importar nacionales y autonómicos</button></div>'
            + '<div class="hc-sub" style="margin:-4px 0 10px">'
            + (S.festRegion == null
                ? 'Elige tu comunidad e importa una vez: a partir de ahí, cada año nuevo se cargan solos.'
                : 'Se cargan solos cada año. Los <b>locales</b> (2 por municipio) añádelos a mano abajo.')
            + '</div>'
            + listaFest
            + '<div class="hc-toolbar" style="margin:12px 0 0">'
            + '<input type="date" class="hc-input" id="hcFestFecha">'
            + '<input type="text" class="hc-input hc-grow" id="hcFestNombre" placeholder="Nombre (ej. Fiesta local)">'
            + '<button class="hc-btn primary" onclick="HC.guardarFestivo()">➕ Añadir</button></div></div>'

            + '<div class="hc-section"><h3><span>⛔ Últimos intentos bloqueados</span><button class="hc-btn mini" onclick="HC.verBloqueos()">Ver</button></h3><div id="hcBloqueos"></div></div>'

            + '<div class="modal-buttons">'
            + '<button class="hc-btn" onclick="HC.cerrar(\'hcModalGestion\');HC.abrirCalendario()">📅 Abrir calendario</button>'
            + '<button class="btn-cancel" onclick="HC.cerrar(\'hcModalGestion\')">Cerrar</button></div>';
    }

    function toggleRestriccion(uid, activo, input) {
        post('toggle_restriccion_horario', { usuario_id: uid, activo: activo }).then(function (d) {
            if (!d.success) { toast('Error: ' + d.error, 'error'); if (input) input.checked = !activo; return; }
            avisoSinHorario(d);
            var u = S.usuarios.find(function (x) { return x.id === uid; });
            if (u) u.restringir_horario = activo;
            if (input) {
                var lab = input.closest('div').querySelector('.hc-switch-label');
                if (lab) lab.textContent = activo ? 'Bloqueo' : 'Libre';
            }
            toast(d.message, 'success');
            rellenarFiltro();
        });
    }

    function toggleTodos(activo) {
        var txt = activo
            ? '¿Activar el bloqueo por horario para TODOS los empleados activos?\n\nQuien no tenga horario configurado no podrá fichar entrada.'
            : '¿Quitar el bloqueo por horario a todos los empleados?';
        if (!confirm(txt)) return;
        post('toggle_restriccion_horario', { todos: true, activo: activo }).then(function (d) {
            if (!d.success) return toast('Error: ' + d.error, 'error');
            avisoSinHorario(d);
            toast(d.message, 'success');
            abrirGestion();
        });
    }

    function avisoSinHorario(d) {
        if (d.sin_horario && d.sin_horario.length) {
            var nombres = d.sin_horario.map(function (u) { return u.nombre; }).join(', ');
            alert('⚠️ Atención: ' + nombres + (d.sin_horario.length > 1 ? ' no tienen' : ' no tiene')
                + ' horario configurado.\n\nCon el bloqueo activo NO podrá' + (d.sin_horario.length > 1 ? 'n' : '')
                + ' fichar entrada hasta que se lo configures.');
        }
    }

    function guardarConfig() {
        post('save_config_horarios', {
            margen_antes_min: parseInt(document.getElementById('hcCfgMargen').value || '0', 10),
            bloquear_festivos: document.getElementById('hcCfgFest').checked,
            usuarios_editan_horario: document.getElementById('hcCfgEdit').checked
        }).then(function (d) {
            toast(d.success ? '✅ Reglas guardadas' : 'Error: ' + d.error, d.success ? 'success' : 'error');
            if (d.success) refrescar();
        });
    }

    function guardarFestivo() {
        var f = document.getElementById('hcFestFecha').value;
        var n = document.getElementById('hcFestNombre').value.trim();
        if (!f || !n) return toast('Indica fecha y nombre del festivo', 'warning');
        post('save_festivo', { fecha: f, nombre: n }).then(function (d) {
            if (!d.success) return toast('Error: ' + d.error, 'error');
            toast('✅ Festivo añadido', 'success');
            abrirGestion(); refrescar();
        });
    }

    function importarFestivos() {
        var region = document.getElementById('hcFestRegion').value;
        var btn = document.getElementById('hcFestImportar');
        if (btn) { btn.disabled = true; btn.textContent = 'Importando…'; }
        post('importar_festivos', { ano: S.festAno, region: region }).then(function (d) {
            if (!d.success) {
                toast('❌ ' + d.error, 'error');
                if (btn) { btn.disabled = false; btn.textContent = '📥 Importar nacionales y autonómicos'; }
                return;
            }
            toast('✅ ' + d.message, 'success');
            abrirGestion(); refrescar();
        });
    }

    function cambiarAnoFestivos(ano) {
        S.festAno = parseInt(ano, 10);
        abrirGestion();
    }

    function borrarFestivo(id) {
        if (!confirm('¿Eliminar este festivo?')) return;
        post('delete_festivo', { id: id }).then(function (d) {
            if (!d.success) return toast('Error: ' + d.error, 'error');
            abrirGestion(); refrescar();
        });
    }

    function verBloqueos() {
        var box = document.getElementById('hcBloqueos');
        box.innerHTML = '<div class="hc-vacio">Cargando…</div>';
        post('get_bloqueos_horario', { limite: 30 }).then(function (d) {
            if (!d.success) { box.innerHTML = '<div class="hc-vacio">' + esc(d.error) + '</div>'; return; }
            if (!d.bloqueos.length) { box.innerHTML = '<div class="hc-vacio">Ningún intento bloqueado. 👍</div>'; return; }
            box.innerHTML = d.bloqueos.map(function (b) {
                return '<div class="hc-detail-row"><span>' + new Date(b.fecha_hora.replace(' ', 'T')).toLocaleString('es-ES', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
                    + ' · ' + esc(b.origen) + '</span><span>' + esc(b.nombre) + '</span></div>';
            }).join('');
        });
    }

    // ================================================================ EDITOR SEMANAL (admin y empleado)
    function abrirEditor(uid) {
        var c = modal('hcModalEditor');
        c.innerHTML = '<h2>✏️ Horario semanal</h2><div class="hc-sub">Cargando…</div>';
        abrir('hcModalEditor');
        post('get_horario_usuario', S.modo === 'admin' ? { usuario_id: uid } : {}).then(function (d) {
            if (!d.success) { c.innerHTML = '<h2>✏️ Horario semanal</h2><div class="result error">' + esc(d.error) + '</div>'; return; }
            S.editor = { uid: d.usuario.id, nombre: d.usuario.nombre, tramos: {}, puede: d.puede_editar, motivo: d.motivo_no_editable, max: d.horas_semanales_max };
            for (var i = 1; i <= 7; i++) S.editor.tramos[i] = [];
            d.tramos.forEach(function (t) { S.editor.tramos[t.dia_semana].push({ i: t.hora_inicio, f: t.hora_fin }); });
            pintarEditor();
        });
    }

    function pintarEditor() {
        var E = S.editor, c = modal('hcModalEditor');
        var ro = !E.puede;
        var filas = '';
        for (var d = 1; d <= 7; d++) {
            var lista = E.tramos[d], minDia = 0;
            var chips = lista.map(function (t, idx) {
                minDia += minTramo(t.i, t.f);
                var noct = t.i && t.f && t.f <= t.i;
                return '<span class="hc-tramo' + (noct ? ' noct' : '') + '" title="' + (noct ? 'Termina al día siguiente' : '') + '">'
                    + '<input type="time" class="hc-input" value="' + esc(t.i) + '" ' + (ro ? 'disabled' : '') + ' onchange="HC._setTramo(' + d + ',' + idx + ',\'i\',this.value)">'
                    + '–<input type="time" class="hc-input" value="' + esc(t.f) + '" ' + (ro ? 'disabled' : '') + ' onchange="HC._setTramo(' + d + ',' + idx + ',\'f\',this.value)">'
                    + (noct ? '🌙' : '')
                    + (ro ? '' : '<button class="hc-x" title="Quitar" onclick="HC._delTramo(' + d + ',' + idx + ')">×</button>')
                    + '</span>';
            }).join('');
            filas += '<div class="hc-dia"><div class="hc-dia-nombre">' + DIAS[d] + '<small>' + (minDia ? fmtMin(minDia) : 'Libre') + '</small></div>'
                + '<div class="hc-tramos">' + (chips || '<span class="hc-vacio">Sin turno</span>') + '</div>'
                + (ro ? '<span></span>' : '<button class="hc-btn mini" onclick="HC._addTramo(' + d + ')">+ tramo</button>') + '</div>';
        }

        var total = 0;
        for (var k = 1; k <= 7; k++) E.tramos[k].forEach(function (t) { total += minTramo(t.i, t.f); });
        var exceso = E.max && total > E.max * 60;

        var copiar = '';
        if (S.modo === 'admin' && S.usuarios.length > 1) {
            copiar = '<details class="hc-section" style="margin-top:12px"><summary style="cursor:pointer;font-weight:700;font-size:14px">📋 Aplicar este mismo horario a otros empleados</summary>'
                + '<div class="hc-chk-list" style="margin-top:10px">'
                + S.usuarios.filter(function (u) { return u.id !== E.uid; }).map(function (u) {
                    return '<label class="hc-check"><input type="checkbox" class="hcCopiarA" value="' + u.id + '"> ' + esc(u.nombre) + '</label>';
                }).join('') + '</div></details>';
        }

        c.innerHTML = '<h2>✏️ Horario de ' + esc(S.modo === 'admin' ? E.nombre : 'la semana') + '</h2>'
            + (ro ? '<div class="hc-banner bloq">🔒 ' + esc(E.motivo || 'Solo lectura') + '</div>' : '<div class="hc-sub">Añade uno o varios tramos por día (jornada partida). Si la hora de fin es menor que la de inicio, el turno acaba al día siguiente 🌙.</div>')
            + (ro ? '' : '<div class="hc-presets">'
                + '<button class="hc-btn mini" onclick="HC._preset(\'partida\')">L–V 9–14 · 16–19</button>'
                + '<button class="hc-btn mini" onclick="HC._preset(\'intensiva\')">L–V 8–15</button>'
                + '<button class="hc-btn mini" onclick="HC._preset(\'continua\')">L–V 9–17</button>'
                + '<button class="hc-btn mini" onclick="HC._preset(\'copiaLunes\')">Copiar lunes a L–V</button>'
                + '<button class="hc-btn mini" onclick="HC._preset(\'vaciar\')">Vaciar</button></div>')
            + '<div class="hc-section" style="padding:6px 14px">' + filas + '</div>'
            + '<div class="hc-total">Total semanal: ' + fmtMin(total)
            + (E.max ? ' <span style="font-weight:500;color:var(--text-sub,#777)">(máximo ' + E.max + 'h)</span>' : '')
            + (exceso ? ' <span class="hc-warn">⚠️ supera el máximo semanal</span>' : '') + '</div>'
            + copiar
            + '<div class="modal-buttons">'
            + '<button class="btn-cancel" onclick="HC.cerrar(\'hcModalEditor\')">' + (ro ? 'Cerrar' : 'Cancelar') + '</button>'
            + (ro ? '' : '<button class="hc-btn primary" id="hcEditorGuardar" onclick="HC.guardarEditor()">💾 Guardar horario</button>')
            + '</div>';
    }

    function setTramo(d, idx, campo, val) { S.editor.tramos[d][idx][campo] = val; pintarEditor(); }
    function delTramo(d, idx) { S.editor.tramos[d].splice(idx, 1); pintarEditor(); }
    function addTramo(d) {
        var l = S.editor.tramos[d];
        var ult = l[l.length - 1];
        l.push(ult ? { i: ult.f, f: '' } : { i: '09:00', f: '14:00' });
        pintarEditor();
    }
    function preset(tipo) {
        var T = S.editor.tramos, d;
        var lv = [1, 2, 3, 4, 5];
        if (tipo === 'vaciar') { for (d = 1; d <= 7; d++) T[d] = []; }
        else if (tipo === 'copiaLunes') {
            if (!T[1].length) return toast('El lunes no tiene tramos para copiar', 'warning');
            lv.forEach(function (x) { T[x] = T[1].map(function (t) { return { i: t.i, f: t.f }; }); });
        } else {
            var base = { partida: [['09:00', '14:00'], ['16:00', '19:00']], intensiva: [['08:00', '15:00']], continua: [['09:00', '17:00']] }[tipo];
            lv.forEach(function (x) { T[x] = base.map(function (b) { return { i: b[0], f: b[1] }; }); });
        }
        pintarEditor();
    }

    function guardarEditor() {
        var E = S.editor, tramos = [];
        for (var d = 1; d <= 7; d++) {
            for (var j = 0; j < E.tramos[d].length; j++) {
                var t = E.tramos[d][j];
                if (!t.i || !t.f) return toast('Completa las horas del ' + DIAS[d].toLowerCase(), 'warning');
                tramos.push({ dia_semana: d, hora_inicio: t.i, hora_fin: t.f });
            }
        }
        var btn = document.getElementById('hcEditorGuardar');
        if (btn) { btn.disabled = true; btn.textContent = 'Guardando…'; }
        var destinos = Array.prototype.map.call(document.querySelectorAll('.hcCopiarA:checked'), function (x) { return +x.value; });

        post('save_horario_usuario', S.modo === 'admin' ? { usuario_id: E.uid, tramos: tramos } : { tramos: tramos })
            .then(function (d) {
                if (!d.success) throw new Error(d.error);
                return destinos.length ? post('copiar_horario', { origen_id: E.uid, destino_ids: destinos }) : { success: true };
            })
            .then(function (d2) {
                if (!d2.success) throw new Error(d2.error);
                toast('✅ Horario guardado' + (destinos.length ? ' y copiado a ' + destinos.length + ' empleado(s)' : ''), 'success');
                cerrar('hcModalEditor');
                refrescar();
                if (S.modo === 'admin' && document.getElementById('hcModalGestion') && document.getElementById('hcModalGestion').classList.contains('active')) abrirGestion();
                if (S.modo === 'user') pintarBannerHoy();
            })
            .catch(function (e) {
                toast('❌ ' + e.message, 'error');
                if (btn) { btn.disabled = false; btn.textContent = '💾 Guardar horario'; }
            });
    }

    // ================================================================ EXCEPCIONES (admin): cambiar un día concreto
    function abrirExcepcion(uid, fecha) {
        var c = modal('hcModalExcep', 'hc-modal-sm');
        var n0 = new Date();
        var hoy = n0.getFullYear() + '-' + ('0' + (n0.getMonth() + 1)).slice(-2) + '-' + ('0' + n0.getDate()).slice(-2);
        S.excep = { uid: uid, fecha: fecha || hoy, tipo: 'especial', tramos: [], nota: '', tiene: false };
        var prepararUsuarios = S.usuarios.length ? Promise.resolve() : cargarUsuarios();
        prepararUsuarios.then(function () {
            c.innerHTML = '<h2>📌 Cambiar un día</h2>'
                + '<div class="hc-sub">Turno distinto o día libre <b>solo para esa fecha</b>. El horario semanal no se toca.</div>'
                + '<div style="display:grid;gap:10px">'
                + '<select class="hc-select" id="hcExUsuario"><option value="">— Empleado —</option><option value="todos">👥 Todos los empleados</option>'
                + S.usuarios.map(function (u) { return '<option value="' + u.id + '">' + esc(u.nombre) + '</option>'; }).join('') + '</select>'
                + '<input type="date" class="hc-input" id="hcExFecha" value="' + S.excep.fecha + '">'
                + '<div id="hcExCuerpo"></div></div>'
                + '<div class="modal-buttons" id="hcExBotones"></div>';
            var sel = document.getElementById('hcExUsuario');
            if (uid) sel.value = String(uid);
            sel.onchange = cargarExcepcion;
            document.getElementById('hcExFecha').onchange = cargarExcepcion;
            abrir('hcModalExcep');
            cargarExcepcion();
        });
    }

    function cargarExcepcion() {
        var uid = document.getElementById('hcExUsuario').value;
        var fecha = document.getElementById('hcExFecha').value;
        S.excep.uid = uid; S.excep.fecha = fecha;
        if (!uid || !fecha) { pintarExcepcion(); return; }
        if (uid === 'todos') { S.excep.tipo = 'libre'; S.excep.tramos = []; S.excep.tiene = false; pintarExcepcion(); return; }
        post('get_excepciones_horario', { usuario_id: +uid, fecha: fecha }).then(function (d) {
            if (!d.success) return toast('Error: ' + d.error, 'error');
            S.excep.tiene = d.tiene_excepcion;
            S.excep.tipo = d.motivo === 'libre' ? 'libre' : 'especial';
            S.excep.tramos = d.tramos.map(function (t) { return { i: t.hora_inicio, f: t.hora_fin }; });
            if (!S.excep.tramos.length && S.excep.tipo === 'especial') S.excep.tramos = [{ i: '09:00', f: '14:00' }];
            S.excep.nota = d.nota || '';
            S.excep.festivo = d.festivo;
            S.excep.motivo = d.motivo;
            pintarExcepcion();
        });
    }

    function pintarExcepcion() {
        var X = S.excep, body = document.getElementById('hcExCuerpo'), bots = document.getElementById('hcExBotones');
        if (!body) return;
        if (!X.uid || !X.fecha) {
            body.innerHTML = '<div class="hc-vacio">Elige empleado y fecha.</div>';
            bots.innerHTML = '<button class="btn-cancel" onclick="HC.cerrar(\'hcModalExcep\')">Cerrar</button>';
            return;
        }
        var estado = X.uid === 'todos' ? 'Se aplicará a todos los empleados activos.'
            : X.tiene ? '★ Este día ya tiene un cambio guardado.'
            : X.motivo === 'sin_turno' ? 'Habitualmente no trabaja este día.' : 'Ahora mismo tiene su horario habitual.';
        body.innerHTML = '<div class="hc-banner" style="margin:0">' + esc(fechaLarga(X.fecha)) + (X.festivo ? ' · 🎉 ' + esc(X.festivo) : '') + '<br><small>' + esc(estado) + '</small></div>'
            + '<div style="display:flex;gap:16px;flex-wrap:wrap">'
            + '<label class="hc-check"><input type="radio" name="hcExTipo" value="especial" ' + (X.tipo === 'especial' ? 'checked' : '') + ' onchange="HC._exTipo(this.value)"> Turno especial</label>'
            + '<label class="hc-check"><input type="radio" name="hcExTipo" value="libre" ' + (X.tipo === 'libre' ? 'checked' : '') + ' onchange="HC._exTipo(this.value)"> Día libre</label></div>'
            + (X.tipo === 'especial'
                ? '<div class="hc-tramos">' + X.tramos.map(function (t, i) {
                    return '<span class="hc-tramo"><input type="time" class="hc-input" value="' + esc(t.i) + '" onchange="HC._exSet(' + i + ',\'i\',this.value)">–'
                        + '<input type="time" class="hc-input" value="' + esc(t.f) + '" onchange="HC._exSet(' + i + ',\'f\',this.value)">'
                        + '<button class="hc-x" onclick="HC._exDel(' + i + ')">×</button></span>';
                }).join('') + '<button class="hc-btn mini" onclick="HC._exAdd()">+ tramo</button></div>'
                : '')
            + '<input type="text" class="hc-input" id="hcExNota" placeholder="Nota (ej. cambio de turno con Luis)" value="' + esc(X.nota) + '">';
        bots.innerHTML = '<button class="btn-cancel" onclick="HC.cerrar(\'hcModalExcep\')">Cancelar</button>'
            + (X.tiene ? '<button class="hc-btn" onclick="HC.restaurarDia()">↩️ Volver al habitual</button>' : '')
            + '<button class="hc-btn primary" onclick="HC.guardarExcepcion()">💾 Guardar</button>';
    }

    function guardarNota() {
        var n = document.getElementById('hcExNota');
        if (n) S.excep.nota = n.value;
    }

    function guardarExcepcion() {
        var X = S.excep;
        X.nota = document.getElementById('hcExNota').value;
        if (X.uid === 'todos' && !confirm('¿Aplicar este cambio a TODOS los empleados el ' + fechaLarga(X.fecha) + '?')) return;
        var body = { usuario_id: X.uid === 'todos' ? 'todos' : +X.uid, fecha: X.fecha, tipo: X.tipo, nota: X.nota };
        if (X.tipo === 'especial') {
            if (X.tramos.some(function (t) { return !t.i || !t.f; })) return toast('Completa las horas del tramo', 'warning');
            body.tramos = X.tramos.map(function (t) { return { hora_inicio: t.i, hora_fin: t.f }; });
        }
        post('save_excepcion_horario', body).then(function (d) {
            if (!d.success) return toast('❌ ' + d.error, 'error');
            toast('✅ ' + d.message, 'success');
            cerrar('hcModalExcep');
            refrescar();
        });
    }

    function restaurarDia() {
        post('delete_excepcion_horario', { usuario_id: +S.excep.uid, fecha: S.excep.fecha }).then(function (d) {
            if (!d.success) return toast('❌ ' + d.error, 'error');
            toast('✅ ' + d.message, 'success');
            cerrar('hcModalExcep');
            refrescar();
        });
    }

    // ================================================================ EMPLEADO
    function initUsuario(selector) {
        S.modo = 'user';
        var host = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (!host) return;
        host.innerHTML = '<div class="hc-wrap">'
            + '<div id="hcBannerHoy"></div>'
            + '<div class="hc-toolbar">'
            + '<label class="hc-check hc-grow"><input type="checkbox" id="hcReales" checked> Ver mis fichajes</label>'
            + '<button class="hc-btn" onclick="HC.abrirEditor()">✏️ Mi horario</button>'
            + '</div>'
            + '<div class="hc-cal" id="hcCalendario"></div>'
            + leyendaHTML()
            + '<div class="hc-resumen" id="hcResumen"></div>'
            + '</div>';
        document.getElementById('hcReales').onchange = function () { S.reales = this.checked; refrescar(); };
        S.calEl = document.getElementById('hcCalendario');
        pintarBannerHoy();
        if (!S.bannerTimer) S.bannerTimer = setInterval(pintarBannerHoy, 5 * 60 * 1000);
        // Si el contenedor está oculto (pestaña/acordeón cerrado), FullCalendar mide mal: montar cuando sea visible
        montarCuandoVisible(host);
    }

    function montarCuandoVisible(host) {
        if (S.cal) { S.cal.updateSize(); return; }
        if (host.offsetParent !== null) { S.cal = montarCalendario(S.calEl); return; }
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (ents) {
                if (ents[0].isIntersecting) { io.disconnect(); S.cal ? S.cal.updateSize() : (S.cal = montarCalendario(S.calEl)); }
            });
            io.observe(host);
        } else {
            S.cal = montarCalendario(S.calEl);
        }
    }

    function pintarBannerHoy() {
        // Pinta en #hcBannerHoy (dentro del calendario) y en cualquier [data-hc-banner] de la página
        var boxes = document.querySelectorAll('#hcBannerHoy, [data-hc-banner]');
        if (!boxes.length) return;
        var prev = S.modo; S.modo = 'user';
        var req = post('get_mi_horario_hoy');
        S.modo = prev;
        req.then(function (d) {
            var html = '';
            if (d.success) {
                var txt;
                if (d.motivo === 'libre') txt = '🌙 Hoy tienes el día libre';
                else if (d.festivo && !d.tramos.length) txt = '🎉 Hoy es festivo: ' + esc(d.festivo);
                else if (!d.tramos.length) txt = 'Hoy no tienes turno asignado';
                else txt = 'Tu turno de hoy: ' + d.tramos.map(function (t) { return '<span class="hc-chip">' + esc(t) + '</span>'; }).join(' ');
                var bloq = d.restringido && !d.puede_fichar;
                html = '<div class="hc-wrap"><div class="hc-banner' + (bloq ? ' bloq' : '') + '"><span>' + txt + '</span>'
                    + (d.restringido ? '<small style="color:var(--text-sub,#777)">' + (bloq ? esc(d.mensaje) : '🔒 Solo puedes fichar entrada dentro de tu horario') + '</small>' : '')
                    + '</div></div>';
            }
            Array.prototype.forEach.call(boxes, function (b) { b.innerHTML = html; });
        });
    }

    // ================================================================ API pública
    return {
        // admin
        abrirCalendario: abrirCalendario,
        cerrarCalendario: cerrarCalendario,
        abrirGestion: abrirGestion,
        abrirEditor: abrirEditor,
        abrirExcepcion: abrirExcepcion,
        toggleRestriccion: toggleRestriccion,
        toggleTodos: toggleTodos,
        guardarConfig: guardarConfig,
        guardarFestivo: guardarFestivo,
        borrarFestivo: borrarFestivo,
        importarFestivos: importarFestivos,
        cambiarAnoFestivos: cambiarAnoFestivos,
        verBloqueos: verBloqueos,
        guardarEditor: guardarEditor,
        guardarExcepcion: guardarExcepcion,
        restaurarDia: restaurarDia,
        // empleado
        initUsuario: initUsuario,
        refrescarBanner: pintarBannerHoy,
        // comunes
        refrescar: refrescar,
        cerrar: cerrar,
        // internos (usados desde onclick generados)
        _setTramo: setTramo, _delTramo: delTramo, _addTramo: addTramo, _preset: preset,
        _exTipo: function (v) { guardarNota(); S.excep.tipo = v; if (v === 'especial' && !S.excep.tramos.length) S.excep.tramos = [{ i: '09:00', f: '14:00' }]; pintarExcepcion(); },
        _exSet: function (i, k, v) { S.excep.tramos[i][k] = v; },
        _exDel: function (i) { guardarNota(); S.excep.tramos.splice(i, 1); pintarExcepcion(); },
        _exAdd: function () { guardarNota(); var l = S.excep.tramos, u = l[l.length - 1]; l.push(u ? { i: u.f, f: '' } : { i: '09:00', f: '14:00' }); pintarExcepcion(); },
        _verEvento: function (i) { var ev = (S.diaEventos || [])[i]; if (ev) detalleEvento(ev); }
    };
})();