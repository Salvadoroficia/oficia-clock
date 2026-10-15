/* ============================================================
   Oficia Clock · Informe mensual de jornada con firma
   - Empleado: aviso de informes pendientes, revisión del mes y firma
               ("Conforme" o "Con observaciones", firma con el dedo opcional).
   - Admin: estado de firmas por mes, PDF por empleado y ZIP de todos.
   Usa los estilos de modal de horarios-calendario.css (.hc-modal).
   Expone un único global: INF
   ============================================================ */
var INF = (function () {
    'use strict';

    var API = 'api.php';
    var S = { periodoAdmin: null, actual: null, pad: null };
    var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    var SEM = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    var ESTADOS = {
        firmado: { txt: 'Firmado', cls: 'ok' },
        pendiente: { txt: 'Pendiente', cls: 'pend' },
        desactualizado: { txt: 'Hay que volver a firmar', cls: 'ko' },
        sin_fichajes: { txt: 'Sin fichajes', cls: 'nada' }
    };

    // ---------------------------------------------------------------- utilidades
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function post(action, body) {
        return (window.__audFetch ? window.fetch : fetch)(API + '?action=' + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {})
        }).then(function (r) { return r.json(); })
          .catch(function (e) { return { success: false, error: 'Error de conexión: ' + e.message }; });
    }
    function toast(msg, tipo) {
        if (typeof window.mostrarToast === 'function') return window.mostrarToast(msg, tipo || 'info');
        var t = document.createElement('div');
        t.className = 'inf-toast inf-toast-' + (tipo || 'info');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('fuera'); }, 3500);
        setTimeout(function () { t.remove(); }, 4000);
    }
    function mesTxt(p) { return MESES[+p.slice(5, 7) - 1] + ' de ' + p.slice(0, 4); }
    function hm(m) { m = Math.round(m || 0); return Math.floor(m / 60) + ':' + ('0' + (m % 60)).slice(-2); }
    function hmLargo(m) { m = Math.round(m || 0); var h = Math.floor(m / 60), r = m % 60; return h + 'h' + (r ? ' ' + r + 'm' : ''); }
    function fDia(iso) { var d = new Date(iso + 'T12:00:00'); return SEM[d.getDay()] + ' ' + ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2); }
    function fHora(s) { if (!s) return '—'; var d = new Date(String(s).replace(' ', 'T')); return d.toLocaleDateString('es-ES') + ' ' + d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }); }
    function estadoBadge(e, firma) {
        var x = ESTADOS[e] || { txt: e, cls: '' };
        var txt = x.txt;
        if (e === 'firmado' && firma && firma.tipo === 'observaciones') { txt = 'Firmado con observaciones'; x = { cls: 'obs' }; }
        return '<span class="inf-badge inf-' + x.cls + '">' + txt + '</span>';
    }
    function urlPdf(p, uid, descargar) {
        return API + '?action=informe_pdf&periodo=' + p + (uid ? '&usuario_id=' + uid : '') + (descargar ? '&descargar=1' : '');
    }
    function modal(id, extra) {
        var el = document.getElementById(id);
        if (!el) {
            el = document.createElement('div');
            el.id = id;
            el.className = 'modal-overlay hc-modal inf-modal ' + (extra || '');
            el.innerHTML = '<div class="modal-content"></div>';
            el.addEventListener('click', function (e) { if (e.target === el) cerrar(id); });
            document.body.appendChild(el);
        }
        return el.querySelector('.modal-content');
    }
    function abrir(id) { document.getElementById(id).classList.add('active'); }
    function cerrar(id) { var el = document.getElementById(id); if (el) el.classList.remove('active'); }

    // ---------------------------------------------------------------- resumen del mes (lo comparten admin y empleado)
    function detalleMesHTML(d) {
        var dias = d.dias.length ? d.dias.map(function (x) {
            return '<div class="inf-dia' + (x.incidencia ? ' inf-inc' : '') + '">'
                + '<span class="inf-d-f">' + fDia(x.fecha) + '</span>'
                + '<span class="inf-d-t">' + x.tramos.map(function (t) { return (t.entrada || '¿?') + ' – ' + (t.salida || '¿?'); }).join('<br>') + '</span>'
                + '<span class="inf-d-n">' + hm(x.neto_min) + (x.descanso_min ? '<small>desc. ' + hm(x.descanso_min) + '</small>' : '') + '</span>'
                + '</div>';
        }).join('') : '<div class="hc-vacio">Sin fichajes este mes.</div>';
        var t = d.totales;
        var aus = d.ausencias.length ? '<h4>Ausencias</h4>' + d.ausencias.map(function (a) {
            return '<div class="inf-li">· ' + esc(a.tipo.replace(/_/g, ' ')) + ': ' + fDia(a.desde) + (a.hasta ? ' → ' + fDia(a.hasta) : ' (sin fin)') + '</div>';
        }).join('') : '';
        var cam = d.cambios.length ? '<h4>Correcciones hechas en tu registro</h4>' + d.cambios.map(function (c) {
            var acc = { INSERT: 'Añadida', UPDATE: 'Modificada', DELETE: 'Eliminada' }[c.accion] || c.accion;
            var tipo = c.tipo === 'In' ? 'entrada' : c.tipo === 'Out' ? 'salida' : 'fichaje';
            var det = c.accion === 'UPDATE' ? fHora(c.antes) + ' → ' + fHora(c.despues) : fHora(c.despues || c.antes);
            return '<div class="inf-li">· ' + acc + ' ' + tipo + ' ' + esc(det) + ' <small>(' + esc(c.por) + (c.motivo ? ': ' + esc(c.motivo) : '') + ')</small></div>';
        }).join('') : '';
        return '<div class="inf-dias">' + dias + '</div>'
            + '<div class="inf-total"><span>Total · ' + t.dias + ' días</span><span>Trabajado ' + hmLargo(t.trabajado_min) + '</span>'
            + (t.descanso_min ? '<span>Descansos ' + hmLargo(t.descanso_min) + '</span>' : '') + '<b>Neto ' + hmLargo(t.neto_min) + '</b></div>'
            + (t.incidencias ? '<div class="inf-aviso-inc">⚠️ ' + t.incidencias + ' día(s) con entrada o salida sin registrar (en rojo). Si no es correcto, firma <b>con observaciones</b>.</div>' : '')
            + aus + cam;
    }

    // ---------------------------------------------------------------- EMPLEADO
    function cargarEmpleado() {
        var aviso = document.querySelector('[data-inf-aviso]');
        var lista = document.querySelector('[data-inf-empleado]');
        if (!aviso && !lista) return;
        post('informe_mis_pendientes').then(function (d) {
            if (!d.success) return;
            var pend = d.meses.filter(function (m) { return m.estado !== 'firmado'; });
            if (aviso) {
                aviso.innerHTML = pend.length
                    ? '<div class="hc-wrap"><div class="hc-banner inf-banner"><span>📝 Tienes <b>' + pend.length + '</b> informe' + (pend.length > 1 ? 's' : '') + ' de jornada por firmar'
                      + ' (' + pend.map(function (m) { return mesTxt(m.periodo); }).join(', ') + ')</span>'
                      + '<button type="button" class="hc-btn primary" onclick="INF.revisar(\'' + pend[pend.length - 1].periodo + '\')">Revisar y firmar</button></div></div>'
                    : '';
            }
            if (lista) {
                lista.innerHTML = '<div class="hc-wrap inf-emp"><h3>📝 Mis informes mensuales</h3>'
                    + (d.meses.length ? d.meses.map(function (m) {
                        var firmado = m.estado === 'firmado';
                        return '<div class="inf-mes">'
                            + '<div><b>' + mesTxt(m.periodo) + '</b> · ' + hmLargo(m.neto_min) + '<br>' + estadoBadge(m.estado, m.firma)
                            + (firmado ? ' <small>' + fHora(m.firma.firmado_en) + '</small>' : '') + '</div>'
                            + '<div class="inf-mes-acc">'
                            + (firmado ? '<a class="hc-btn mini" href="' + urlPdf(m.periodo, null, true) + '">📄 PDF</a>' : '')
                            + '<button type="button" class="hc-btn mini' + (firmado ? '' : ' primary') + '" onclick="INF.revisar(\'' + m.periodo + '\')">' + (firmado ? 'Ver' : '✍️ Firmar') + '</button>'
                            + '</div></div>';
                    }).join('') : '<div class="hc-vacio">Aún no hay meses cerrados con fichajes.</div>')
                    + '</div>';
            }
        });
    }

    function revisar(periodo) {
        var c = modal('infModalFirma');
        c.innerHTML = '<h2>📝 Registro de jornada</h2><div class="hc-sub">Cargando…</div>';
        abrir('infModalFirma');
        post('informe_mio', { periodo: periodo }).then(function (r) {
            if (!r.success) { c.innerHTML = '<h2>📝 Registro de jornada</h2><div class="hc-banner bloq">' + esc(r.error) + '</div>' + cerrarBtn('infModalFirma'); return; }
            S.actual = r;
            var d = r.datos, emp = r.empresa || {};
            var cab = '<h2>📝 ' + esc(mesTxt(periodo).replace(/^./, function (x) { return x.toUpperCase(); })) + '</h2>'
                + '<div class="hc-sub">' + esc(emp.empresa || '') + (emp.cif ? ' · CIF ' + esc(emp.cif) : '') + ' — ' + esc(d.usuario.nombre) + '</div>';
            var pie;
            if (r.estado === 'firmado') {
                pie = '<div class="hc-banner inf-firmado">✅ Firmado ' + (r.firma.tipo === 'observaciones' ? '<b>con observaciones</b>' : '<b>conforme</b>') + ' el ' + fHora(r.firma.firmado_en)
                    + '<br><small>Código de firma: ' + esc(r.firma.codigo) + (r.firma.observaciones ? ' · «' + esc(r.firma.observaciones) + '»' : '') + '</small></div>'
                    + '<div class="modal-buttons"><a class="hc-btn" href="' + urlPdf(periodo, null, true) + '">📄 Descargar PDF</a>'
                    + '<button type="button" class="btn-cancel" onclick="INF.cerrar(\'infModalFirma\')">Cerrar</button></div>';
            } else if (!r.cerrado) {
                pie = '<div class="hc-banner">El mes aún no ha terminado. Podrás firmarlo a partir del día 1.</div>' + cerrarBtn('infModalFirma');
            } else {
                pie = (r.estado === 'desactualizado'
                        ? '<div class="hc-banner bloq">⚠️ Tus fichajes de este mes se corrigieron después de que firmaras el ' + fHora(r.firma.firmado_en) + '. Revísalo y vuelve a firmar.</div>' : '')
                    + '<div class="inf-firmar">'
                    + '<div class="inf-opciones">'
                    + '<label class="hc-check"><input type="radio" name="infTipo" value="conforme" checked> ✅ Estoy conforme</label>'
                    + '<label class="hc-check"><input type="radio" name="infTipo" value="observaciones"> ✍️ Firmo con observaciones</label></div>'
                    + '<textarea class="hc-input" id="infObs" rows="3" maxlength="1000" placeholder="¿Qué no está bien? (ej. el día 3 salí a las 15:00)" hidden></textarea>'
                    + '<div class="inf-pad-wrap"><div class="inf-pad-cab"><span>Firma con el dedo <small>(opcional)</small></span><button type="button" class="hc-btn mini" id="infPadBorrar">Borrar</button></div>'
                    + '<canvas id="infPad" class="inf-pad"></canvas></div>'
                    + '<label class="hc-check inf-chk"><input type="checkbox" id="infRevisado"> He revisado mis entradas y salidas de este mes</label>'
                    + '</div>'
                    + '<div class="modal-buttons"><button type="button" class="btn-cancel" onclick="INF.cerrar(\'infModalFirma\')">Ahora no</button>'
                    + '<button type="button" class="hc-btn primary" id="infBtnFirmar">✍️ Firmar</button></div>';
            }
            c.innerHTML = cab + detalleMesHTML(d) + pie;
            if (r.estado !== 'firmado' && r.cerrado) prepararFirma(periodo);
        });
    }

    function prepararFirma(periodo) {
        var obs = document.getElementById('infObs');
        Array.prototype.forEach.call(document.querySelectorAll('input[name="infTipo"]'), function (r) {
            r.onchange = function () { obs.hidden = r.value !== 'observaciones' || !r.checked; if (!obs.hidden) obs.focus(); };
        });
        S.pad = crearPad(document.getElementById('infPad'));
        document.getElementById('infPadBorrar').onclick = function () { S.pad.borrar(); };
        document.getElementById('infBtnFirmar').onclick = function () {
            var tipo = document.querySelector('input[name="infTipo"]:checked').value;
            if (!document.getElementById('infRevisado').checked) return toast('Marca la casilla de que has revisado tu registro', 'warning');
            if (tipo === 'observaciones' && obs.value.trim().length < 5) { obs.focus(); return toast('Escribe tus observaciones', 'warning'); }
            var btn = this;
            btn.disabled = true; btn.textContent = 'Firmando…';
            post('informe_firmar', {
                periodo: periodo, hash: S.actual.datos.hash, tipo: tipo,
                observaciones: tipo === 'observaciones' ? obs.value.trim() : '',
                firma_img: S.pad.vacio() ? '' : S.pad.imagen()
            }).then(function (d) {
                if (!d.success) {
                    toast('❌ ' + d.error, 'error');
                    btn.disabled = false; btn.textContent = '✍️ Firmar';
                    if (d.recargar) revisar(periodo);
                    return;
                }
                toast('✅ ' + d.message, 'success');
                cargarEmpleado();
                revisar(periodo);
            });
        };
    }

    // Lienzo de firma (ratón, dedo o lápiz)
    function crearPad(cv) {
        var ctx, dibujando = false, hay = false, ult = null;
        function ajustar() {
            var r = cv.getBoundingClientRect(), k = window.devicePixelRatio || 1;
            cv.width = Math.max(1, r.width * k); cv.height = Math.max(1, r.height * k);
            ctx = cv.getContext('2d');
            ctx.scale(k, k);
            ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
            hay = false;
        }
        function pos(e) { var r = cv.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
        cv.addEventListener('pointerdown', function (e) { dibujando = true; ult = pos(e); cv.setPointerCapture(e.pointerId); e.preventDefault(); });
        cv.addEventListener('pointermove', function (e) {
            if (!dibujando) return;
            var p = pos(e);
            ctx.beginPath(); ctx.moveTo(ult.x, ult.y); ctx.lineTo(p.x, p.y); ctx.stroke();
            ult = p; hay = true; e.preventDefault();
        });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { cv.addEventListener(ev, function () { dibujando = false; }); });
        setTimeout(ajustar, 30);
        return {
            borrar: ajustar,
            vacio: function () { return !hay; },
            imagen: function () {
                // JPEG con fondo blanco y como mucho 600 px de ancho
                var w = Math.min(600, cv.width), h = Math.round(cv.height * w / cv.width);
                var o = document.createElement('canvas'); o.width = w; o.height = h;
                var c2 = o.getContext('2d');
                c2.fillStyle = '#fff'; c2.fillRect(0, 0, w, h);
                c2.drawImage(cv, 0, 0, w, h);
                return o.toDataURL('image/jpeg', 0.85);
            }
        };
    }

    function cerrarBtn(id) {
        return '<div class="modal-buttons"><button type="button" class="btn-cancel" onclick="INF.cerrar(\'' + id + '\')">Cerrar</button></div>';
    }

    // ---------------------------------------------------------------- ADMIN
    function abrirPanel(periodo) {
        if (!periodo) {
            var d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - 1);
            periodo = S.periodoAdmin || (d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2));
        }
        S.periodoAdmin = periodo;
        var c = modal('infModalPanel', 'inf-panel');
        c.innerHTML = '<h2>📝 Informes mensuales firmados</h2><div class="hc-sub">Cargando…</div>';
        abrir('infModalPanel');
        post('informe_resumen', { periodo: periodo }).then(function (r) {
            if (!r.success) { c.innerHTML = '<h2>📝 Informes mensuales</h2><div class="hc-banner bloq">' + esc(r.error) + '</div>' + cerrarBtn('infModalPanel'); return; }
            var opts = '', h = new Date(); h.setDate(1);
            for (var i = 0; i < 13; i++) {
                var p = h.getFullYear() + '-' + ('0' + (h.getMonth() + 1)).slice(-2);
                opts += '<option value="' + p + '"' + (p === periodo ? ' selected' : '') + '>' + mesTxt(p) + (i === 0 ? ' (en curso)' : '') + '</option>';
                h.setMonth(h.getMonth() - 1);
            }
            var k = r.cuenta, cfg = r.config || {};
            var filas = r.filas.filter(function (f) { return f.estado !== 'sin_fichajes'; });
            c.innerHTML = '<h2>📝 Informes mensuales firmados</h2>'
                + '<div class="hc-sub">Cada empleado revisa y firma su registro de jornada del mes desde su app. Si después se corrige un fichaje, la firma caduca y tendrá que volver a firmar.</div>'
                + (!cfg.empresa ? '<div class="hc-banner bloq">Falta el nombre y CIF de la empresa para los PDF. <button type="button" class="hc-btn mini" onclick="INF._cfg()">Configurar</button></div>' : '')
                + '<div class="hc-toolbar">'
                + '<select class="hc-select" id="infPeriodo">' + opts + '</select>'
                + '<a class="hc-btn" href="' + API + '?action=informe_zip&periodo=' + periodo + '">📦 Descargar todos (ZIP)</a>'
                + '<button type="button" class="hc-btn" onclick="INF._cfg()">🏢 Datos de la empresa</button></div>'
                + (!r.cerrado ? '<div class="hc-banner">Este mes aún no ha terminado: los empleados podrán firmarlo a partir del día 1.</div>' : '')
                + '<div class="inf-cuenta">'
                + '<span class="inf-badge inf-ok">' + k.firmado + ' firmados</span>'
                + (k.observaciones ? '<span class="inf-badge inf-obs">' + k.observaciones + ' con observaciones</span>' : '')
                + '<span class="inf-badge inf-pend">' + k.pendiente + ' pendientes</span>'
                + (k.desactualizado ? '<span class="inf-badge inf-ko">' + k.desactualizado + ' por volver a firmar</span>' : '')
                + '</div>'
                + '<div id="infCfgBox"></div>'
                + (filas.length ? '<div class="inf-tabla">' + filas.map(function (f) {
                    return '<div class="inf-fila">'
                        + '<div class="inf-f-nom"><b>' + esc(f.nombre) + '</b><small>' + f.dias + ' días · ' + hmLargo(f.neto_min)
                        + (f.incidencias ? ' · <span class="hc-warn">⚠️ ' + f.incidencias + ' sin cerrar</span>' : '')
                        + (f.cambios ? ' · ' + f.cambios + ' correcciones' : '') + '</small></div>'
                        + '<div>' + estadoBadge(f.estado, f.firma)
                        + (f.firma ? '<small class="inf-f-cuando">' + fHora(f.firma.firmado_en) + '</small>' : '')
                        + (f.firma && f.firma.observaciones ? '<div class="inf-obs-txt">«' + esc(f.firma.observaciones) + '»</div>' : '') + '</div>'
                        + '<div class="inf-f-acc"><a class="hc-btn mini" target="_blank" href="' + urlPdf(periodo, f.usuario_id) + '">📄 PDF</a></div>'
                        + '</div>';
                }).join('') + '</div>' : '<div class="hc-vacio" style="padding:12px 0">Nadie fichó en ' + mesTxt(periodo) + '.</div>')
                + cerrarBtn('infModalPanel');
            document.getElementById('infPeriodo').onchange = function () { abrirPanel(this.value); };
        });
    }

    function configEmpresa() {
        var box = document.getElementById('infCfgBox');
        if (!box) return;
        if (box.innerHTML) { box.innerHTML = ''; return; }
        post('informe_config').then(function (r) {
            var c = (r && r.config) || {};
            box.innerHTML = '<div class="hc-section"><h3>🏢 Datos que aparecen en los PDF</h3><div class="inf-cfg">'
                + '<input class="hc-input" id="infEmp" placeholder="Razón social" value="' + esc(c.empresa) + '">'
                + '<input class="hc-input" id="infCif" placeholder="CIF" value="' + esc(c.cif) + '">'
                + '<input class="hc-input" id="infCentro" placeholder="Centro de trabajo (dirección o nombre)" value="' + esc(c.centro) + '">'
                + '<button type="button" class="hc-btn primary" id="infCfgGuardar">💾 Guardar</button></div></div>';
            document.getElementById('infCfgGuardar').onclick = function () {
                post('informe_config_guardar', { empresa: document.getElementById('infEmp').value, cif: document.getElementById('infCif').value, centro: document.getElementById('infCentro').value })
                    .then(function (d) { toast(d.success ? '✅ ' + d.message : '❌ ' + d.error, d.success ? 'success' : 'error'); if (d.success) abrirPanel(S.periodoAdmin); });
            };
        });
    }

    // ---------------------------------------------------------------- estilos
    function estilos() {
        if (document.getElementById('infEstilos')) return;
        var st = document.createElement('style');
        st.id = 'infEstilos';
        st.textContent = [
            '.inf-panel .modal-content{max-width:860px}',
            '.inf-badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap}',
            '.inf-ok{background:#dcfce7;color:#166534}.inf-obs{background:#fef3c7;color:#92400e}.inf-pend{background:#e0e7ff;color:#3730a3}',
            '.inf-ko{background:#fee2e2;color:#b91c1c}.inf-nada{background:#f1f5f9;color:#64748b}',
            '.inf-cuenta{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}',
            '.inf-tabla{border:1px solid var(--hc-border);border-radius:12px;overflow:hidden}',
            '.inf-fila{display:grid;grid-template-columns:1.3fr 1.2fr auto;gap:10px;align-items:center;padding:10px 12px;border-top:1px solid var(--hc-border);font-size:13px}',
            '.inf-fila:first-child{border-top:none}',
            '.inf-f-nom small,.inf-f-cuando{display:block;color:var(--hc-sub);font-size:12px;margin-top:2px}',
            '.inf-obs-txt{font-size:12px;margin-top:4px;color:#92400e}',
            '.inf-cfg{display:grid;grid-template-columns:2fr 1fr;gap:8px}.inf-cfg #infCentro{grid-column:1/-1}.inf-cfg button{justify-self:start}',
            '.inf-modal h4{font-size:14px;margin:14px 0 6px}',
            '.inf-dias{border:1px solid var(--hc-border);border-radius:10px;overflow:hidden;max-height:42vh;overflow-y:auto}',
            '.inf-dia{display:grid;grid-template-columns:72px 1fr 64px;gap:8px;padding:6px 10px;border-top:1px solid var(--hc-border);font-size:13px;align-items:center}',
            '.inf-dia:first-child{border-top:none}.inf-dia:nth-child(even){background:var(--hc-soft)}',
            '.inf-d-f{color:var(--hc-sub)}.inf-d-n{text-align:right;font-weight:700}.inf-d-n small{display:block;font-weight:500;color:var(--hc-sub);font-size:10px}',
            '.inf-inc .inf-d-t{color:#b91c1c;font-weight:700}',
            '.inf-total{display:flex;flex-wrap:wrap;gap:6px 14px;justify-content:space-between;padding:10px 12px;margin-top:8px;border-radius:10px;background:var(--hc-soft);font-size:13px}',
            '.inf-aviso-inc{font-size:12px;color:#b91c1c;margin-top:6px}',
            '.inf-li{font-size:13px;padding:2px 0}.inf-li small{color:var(--hc-sub)}',
            '.inf-firmar{margin-top:14px;padding:12px;border:1px solid var(--hc-border);border-radius:12px}',
            '.inf-opciones{display:flex;flex-wrap:wrap;gap:8px 18px;margin-bottom:8px}',
            '.inf-firmar textarea{width:100%;margin-bottom:8px;resize:vertical;font-family:inherit}',
            '.inf-pad-cab{display:flex;justify-content:space-between;align-items:center;font-size:13px;font-weight:600;margin-bottom:4px}',
            '.inf-pad{width:100%;height:150px;border:2px dashed var(--hc-border);border-radius:10px;background:#fff;touch-action:none;display:block;cursor:crosshair}',
            '.inf-chk{margin-top:10px}',
            '.inf-firmado{display:block}',
            '.inf-banner{justify-content:space-between}',
            '.inf-emp h3{font-size:16px;margin:22px 0 10px}',
            '.inf-mes{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--hc-border);border-radius:10px;margin-bottom:8px;background:var(--hc-soft);font-size:14px}',
            '.inf-mes small{color:var(--hc-sub);font-size:12px}.inf-mes-acc{display:flex;gap:6px;flex-shrink:0}',
            'a.hc-btn{text-decoration:none;display:inline-block}',
            '.inf-toast{position:fixed;left:50%;bottom:90px;transform:translateX(-50%);background:#1f2937;color:#fff;padding:12px 18px;border-radius:12px;font-size:14px;z-index:100001;box-shadow:0 8px 30px rgba(0,0,0,.25);transition:opacity .4s;max-width:90vw}',
            '.inf-toast-error{background:#b91c1c}.inf-toast-success{background:#15803d}.inf-toast-warning{background:#b45309}.inf-toast.fuera{opacity:0}',
            '@media (max-width:640px){.inf-fila{grid-template-columns:1fr auto}.inf-fila>div:nth-child(2){grid-column:1/-1;grid-row:2}.inf-cfg{grid-template-columns:1fr}}'
        ].join('\n');
        document.head.appendChild(st);
    }

    function autoInit() {
        estilos();
        if (document.querySelector('[data-inf-aviso], [data-inf-empleado]')) cargarEmpleado();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autoInit);
    else autoInit();

    return {
        abrirPanel: function (p) { estilos(); abrirPanel(p); },
        revisar: revisar,
        recargar: cargarEmpleado,
        cerrar: cerrar,
        _cfg: configEmpresa
    };
})();
