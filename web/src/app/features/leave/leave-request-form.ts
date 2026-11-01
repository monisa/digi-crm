import { Component, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Subject, debounceTime, switchMap } from 'rxjs';
import { Balance, HrService } from '../../core/hr.service';
import { readErrors } from '../../core/http-errors';
import { Sheet } from '../../shared/sheet';

@Component({
  selector: 'app-leave-request-form',
  imports: [FormsModule, Sheet],
  template: `
    <app-sheet title="Request leave" subtitle="Sundays and public holidays are not counted." width="480px" (closed)="closed.emit()">
      @if (message()) { <div class="alert error">{{ message() }}</div> }
      <div class="field compact" [class.invalid]="errors()['leave_type_id']">
        <label for="l-type">Leave type <span class="req">*</span></label>
        <select id="l-type" [(ngModel)]="typeId">
          @for (b of balances(); track b.type.id) {
            <option [ngValue]="b.type.id">{{ b.type.name }}{{ b.remaining !== null ? ' — ' + b.remaining + ' left' : ' — unpaid' }}</option>
          }
        </select>
        @if (errors()['leave_type_id']) { <div class="err">{{ errors()['leave_type_id'] }}</div> }
      </div>
      <div class="row-2">
        <div class="field compact" [class.invalid]="errors()['from_date']">
          <label for="l-from">From <span class="req">*</span></label>
          <input id="l-from" type="date" [(ngModel)]="from" (ngModelChange)="onDates()" />
        </div>
        <div class="field compact" [class.invalid]="errors()['to_date']">
          <label for="l-to">To <span class="req">*</span></label>
          <input id="l-to" type="date" [(ngModel)]="to" [min]="from" (ngModelChange)="onDates()" [disabled]="half" />
        </div>
      </div>
      @if (errors()['from_date'] || errors()['to_date'] || errors()['half_day']) {
        <div class="err-line">{{ errors()['from_date'] || errors()['to_date'] || errors()['half_day'] }}</div>
      }
      <label class="check"><input type="checkbox" [(ngModel)]="half" (ngModelChange)="onHalf()" /> Half day</label>
      <div class="field" [class.invalid]="errors()['reason']">
        <label for="l-reason">Reason <span class="req">*</span></label>
        <textarea id="l-reason" rows="3" [(ngModel)]="reason"></textarea>
        @if (errors()['reason']) { <div class="err">{{ errors()['reason'] }}</div> }
      </div>

      <div class="days" [class.zero]="days() === 0">
        @if (days() === null) { Pick your dates } @else if (days() === 0) { Those dates are weekly offs or holidays }
        @else { This uses <b>{{ days() }}</b> working day{{ days() === 1 ? '' : 's' }} }
      </div>

      <div class="form-actions">
        <button type="button" class="btn-ghost" (click)="closed.emit()">Cancel</button>
        <button class="btn" (click)="submit()" [disabled]="busy() || !days()">Send request</button>
      </div>
    </app-sheet>
  `,
  styles: `
    .err-line { color: var(--crit); font-size: 12.5px; margin-top: 6px; }
    .days { margin-top: 18px; padding: 14px; border-radius: 12px; background: var(--brand-soft); color: var(--brand-deep); text-align: center; font-size: 14px; transition: background 0.3s; }
    .days b { font-size: 20px; }
    .days.zero { background: #fdecec; color: #b42318; }
  `,
})
export class LeaveRequestForm {
  balances = input.required<Balance[]>();
  closed = output<void>();
  saved = output<void>();

  private hr = inject(HrService);
  protected typeId = 0;
  protected from = '';
  protected to = '';
  protected half = false;
  protected reason = '';
  protected readonly days = signal<number | null>(null);
  protected readonly busy = signal(false);
  protected readonly message = signal('');
  protected readonly errors = signal<Record<string, string>>({});
  private dates$ = new Subject<void>();

  constructor() {
    this.dates$
      .pipe(debounceTime(250), switchMap(() => this.hr.previewDays(this.from, this.to, this.half)))
      .subscribe({ next: (r) => this.days.set(r.days), error: () => this.days.set(null) });
  }

  ngOnInit(): void {
    this.typeId = this.balances()[0]?.type.id ?? 0;
  }

  onDates(): void {
    if (this.from && (!this.to || this.to < this.from)) this.to = this.from;
    if (this.half) this.to = this.from;
    if (this.from && this.to) this.dates$.next();
  }

  onHalf(): void {
    this.onDates();
  }

  submit(): void {
    if (!this.reason.trim()) {
      this.errors.set({ reason: 'Please add a short reason.' });
      return;
    }
    this.busy.set(true);
    this.errors.set({});
    this.message.set('');
    this.hr.requestLeave({ leave_type_id: this.typeId, from_date: this.from, to_date: this.to, half_day: this.half, reason: this.reason }).subscribe({
      next: () => this.saved.emit(),
      error: (e) => {
        const r = readErrors(e);
        this.errors.set(r.fields);
        this.message.set(r.message);
        this.busy.set(false);
      },
    });
  }
}
