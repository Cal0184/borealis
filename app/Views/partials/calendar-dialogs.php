<?php
use Borealis\Services\Calendars;
use Borealis\Services\Events;
?>
<dialog class="modal lg" id="dlg-event" aria-labelledby="dlg-event-title">
  <form data-api="/api/events" data-close data-event-form>
    <input type="hidden" name="id" value="">
    <div class="modal-head"><h2 id="dlg-event-title" data-event-heading>Nuevo evento</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <p class="notice small" data-event-note hidden></p>
      <fieldset class="plain" data-event-fields>
        <div class="segmented seg-radio" role="radiogroup" aria-label="Tipo">
          <label><input type="radio" name="kind" value="event" checked> <?= icon('calendar', 'sm') ?> Evento</label>
          <label><input type="radio" name="kind" value="meeting"> <?= icon('users', 'sm') ?> Reunión</label>
        </div>
        <div class="field"><label for="ev-title">Título</label><input class="input" id="ev-title" name="title" required maxlength="255" autocomplete="off"><span class="error" data-error-for="title"></span></div>
        <div class="field"><label for="ev-cal">Calendario</label>
          <select class="select" id="ev-cal" name="calendar_id" required>
            <?php foreach (['personal' => 'Personal (solo tú)', 'shared' => 'De equipo'] as $gk => $gl): $g = array_filter($editableCals, fn($c) => $c['kind'] === $gk); if (!$g) continue; ?><optgroup label="<?= e($gl) ?>"><?php foreach ($g as $c): ?><option value="<?= (int)$c['id'] ?>" data-source="<?= e($c['source']) ?>" data-purpose="<?= e((string)$c['purpose']) ?>" data-kind="<?= e($c['kind']) ?>"><?= e($c['name']) ?><?= $c['source'] === 'google' ? ' (Google)' : '' ?><?= !empty($c['team_name']) ? ' · ' . e($c['team_name']) : '' ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
          </select><span class="error" data-error-for="calendar_id"></span></div>
        <label class="check mb-sm"><input type="checkbox" name="all_day" value="1" data-allday> Todo el día</label>
        <div class="ev-when">
          <div class="field"><label for="ev-sd">Empieza</label><input class="input" type="date" id="ev-sd" name="start_date" required><span class="error" data-error-for="start_date"></span></div>
          <div class="field" data-time><label for="ev-st">Hora</label><input class="input" type="time" id="ev-st" name="start_time" step="300"><span class="error" data-error-for="start_time"></span></div>
          <div class="field"><label for="ev-ed">Termina</label><input class="input" type="date" id="ev-ed" name="end_date"><span class="error" data-error-for="end_date"></span></div>
          <div class="field" data-time><label for="ev-et">Hora</label><input class="input" type="time" id="ev-et" name="end_time" step="300"><span class="error" data-error-for="end_time"></span></div>
        </div>
        <div class="form-row">
          <div class="field"><label for="ev-rec">Repetir</label><select class="select" id="ev-rec" name="recurrence" data-recurrence><?php foreach (Events::RECURRENCE as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select><span class="error" data-error-for="recurrence"></span></div>
          <div class="field" data-until hidden><label for="ev-until">Hasta (opcional)</label><input class="input" type="date" id="ev-until" name="recurrence_until"><span class="error" data-error-for="recurrence_until"></span></div>
          <div class="field"><label for="ev-rem">Recordatorio</label><select class="select" id="ev-rem" name="reminder_minutes"><?php foreach (Events::REMINDERS as $k => $v): ?><option value="<?= e((string)$k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
          <div class="field"><label for="ev-loc">Lugar</label><input class="input" id="ev-loc" name="location" maxlength="255" placeholder="Sala, dirección…"></div>
          <div class="field"><label for="ev-url">Enlace de videollamada</label><input class="input" type="url" id="ev-url" name="video_url" maxlength="500" placeholder="https://…"><span class="error" data-error-for="video_url"></span></div>
        </div>
        <div class="field" data-participants>
          <span class="label">Participantes</span>
          <input type="hidden" name="participant_ids[]" value="">
          <div class="multi-pick-list cal-people">
            <?php foreach ($people as $m): ?><label class="check"><input type="checkbox" name="participant_ids[]" value="<?= (int)$m['id'] ?>"> <?= avatar($m, 'sm') ?> <?= e($m['name']) ?></label><?php endforeach; ?>
          </div>
          <span class="hint">Cada participante recibe un aviso y ve la reunión en su calendario.</span>
          <span class="error" data-error-for="participant_ids"></span>
        </div>
        <details class="more-options"><summary><?= icon('plus', 'sm') ?> Descripción y vínculos</summary>
          <div class="field"><label for="ev-desc">Descripción</label><textarea class="textarea" id="ev-desc" name="description" rows="3"></textarea></div>
          <div class="form-row">
            <div class="field"><label for="ev-proj">Proyecto</label><select class="select" id="ev-proj" name="project_id"><option value="">Ninguno</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="ev-team">Equipo</label><select class="select" id="ev-team" name="team_id"><option value="">Ninguno</option><?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
          </div>
        </details>
      </fieldset>
      <p class="small muted" data-event-meta></p>
    </div>
    <div class="modal-foot">
      <div class="row wrap grow" data-event-actions hidden>
        <a class="btn btn--sm" data-event-video target="_blank" rel="noopener" hidden><?= icon('video', 'sm') ?> Unirse</a>
        <button type="button" class="btn btn--sm" data-event-cancel><?= icon('block', 'sm') ?> Cancelar evento</button>
        <button type="button" class="btn btn--sm" data-event-restore hidden><?= icon('restore', 'sm') ?> Reactivar</button>
        <button type="button" class="btn btn--sm btn--danger" data-event-delete><?= icon('trash', 'sm') ?> Eliminar</button>
      </div>
      <button type="button" class="btn" data-close-dialog>Cerrar</button>
      <button type="submit" class="btn btn--primary" data-event-save>Guardar</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="dlg-calendar" aria-labelledby="dlg-calendar-title">
  <form data-api="/api/calendars" data-close data-calendar-form>
    <input type="hidden" name="id" value="">
    <div class="modal-head"><h2 id="dlg-calendar-title" data-calendar-heading>Nuevo calendario</h2><button type="button" class="btn btn--ghost btn--icon" data-close-dialog aria-label="Cerrar"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-error" data-form-error></div>
      <div class="field"><label for="cal-name">Nombre</label><input class="input" id="cal-name" name="name" required maxlength="120" autocomplete="off"><span class="error" data-error-for="name"></span></div>
      <div class="field"><span class="label">Color</span>
        <div class="swatches" role="radiogroup" aria-label="Color">
          <?php foreach (Calendars::COLORS as $i => $col): ?><label class="swatch" style="--c:<?= $col ?>"><input type="radio" name="color" value="<?= $col ?>"<?= $i === 0 ? ' checked' : '' ?>><span class="sr-only"><?= $col ?></span></label><?php endforeach; ?>
        </div>
      </div>
      <div class="field" data-cal-kind><span class="label">¿Quién lo ve?</span>
        <label class="check"><input type="radio" name="kind" value="personal"> <?= icon('lock', 'sm') ?> Personal: solo yo (privado)</label>
        <label class="check"><input type="radio" name="kind" value="shared" checked> <?= icon('users', 'sm') ?> De equipo: compartido</label>
      </div>
      <div class="field" data-cal-team><label for="cal-team">Equipo</label>
        <select class="select" id="cal-team" name="team_id"><option value="">Todo el espacio de trabajo</option><?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select>
        <span class="hint">Si eliges un equipo, solo sus integrantes ven este calendario.</span><span class="error" data-error-for="team_id"></span>
      </div>
      <div class="field" data-cal-access><label for="cal-acc">Las demás personas pueden…</label>
        <select class="select" id="cal-acc" name="default_access"><option value="read">Ver sus eventos</option><option value="edit">Ver y crear o cambiar eventos</option><option value="none">Nada (solo quien añadas)</option></select>
      </div>
      <div class="field"><label for="cal-purpose">Uso</label><select class="select" id="cal-purpose" name="purpose"><?php foreach (Calendars::PURPOSES as $k => $v): ?><option value="<?= $k ?>"<?= $k === 'other' ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div data-cal-delete-box hidden>
        <hr>
        <p class="small"><strong>Eliminar este calendario</strong>. ¿Qué hacemos con sus eventos?</p>
        <div class="row wrap">
          <label class="sr-only" for="cal-move">Mover los eventos a</label>
          <select class="select sm" id="cal-move" data-cal-move>
            <option value="">Eliminarlos también</option>
            <?php foreach ($editableCals as $c): if ($c['source'] === 'google') continue; ?><option value="<?= (int)$c['id'] ?>">Moverlos a «<?= e($c['name']) ?>»</option><?php endforeach; ?>
          </select>
          <button type="button" class="btn btn--sm btn--danger" data-cal-delete><?= icon('trash', 'sm') ?> Eliminar calendario</button>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn" data-close-dialog>Cancelar</button>
      <button type="submit" class="btn btn--primary">Guardar</button>
    </div>
  </form>
</dialog>
