import { Component, input, output } from '@angular/core';
import { LeaveRequest, LeaveSummary } from '../../core/hr.service';

/** Balance rings plus the list of requests. Used on the Leave page and on employee profiles. */
@Component({
  selector: 'app-leave-balances',
  template: `
    <div class="bal">
      @for (b of summary().balances; track b.type.id; let i = $index) {
        <div class="card bcard" [style.animation-delay.s]="i * 0.06" [attr.data-code]="b.type.code">
          @if (b.allotted !== null) {
            <svg viewBox="0 0 64 64" class="ring" aria-hidden="true">
              <circle cx="32" cy="32" r="26" class="track" />
              @if (b.used + b.pending > 0) {
                <circle cx="32" cy="32" r="26" class="used" [style.stroke-dasharray]="dash(b.used + b.pending, b.allotted)" />
              }
            </svg>
            <div>
              <b>{{ b.remaining }} <small>of {{ b.allotted }} left</small></b>
              <span>{{ b.type.name }}</span>
              <small class="muted">{{ b.used }} used@if (b.pending) { · {{ b.pending }} pending }</small>
            </div>
          } @else {
            <div class="lop">₹</div>
            <div>
              <b>{{ b.used }} <small>day{{ b.used === 1 ? '' : 's' }}</small></b>
              <span>{{ b.type.name }}</span>
              <small class="muted">Unpaid · no limit@if (b.pending) { · {{ b.pending }} pending }</small>
            </div>
          }
        </div>
      }
    </div>

    <h3 class="h">Requests in {{ summary().year }}</h3>
    <div class="reqs">
      @for (r of summary().requests; track r.id) {
        <div class="req" [attr.data-status]="r.status">
          <div class="date"><b>{{ day(r.from) }}</b><span>{{ mon(r.from) }}</span></div>
          <div class="grow">
            <b>{{ r.type.name }} · {{ r.days }} day{{ r.days === 1 ? '' : 's' }}@if (r.half_day) { (half) }</b>
            <small>{{ range(r) }}@if (r.reason) { · {{ r.reason }} }</small>
            @if (r.approver_comment) { <small class="note">“{{ r.approver_comment }}” — {{ r.approver }}</small> }
          </div>
          <span class="badge" [class.b-warn]="r.status === 'pending'" [class.b-good]="r.status === 'approved'"
                [class.b-crit]="r.status === 'rejected'" [class.b-info]="r.status === 'cancelled'">{{ r.status }}</span>
          @if (canCancel() && r.status === 'pending') {
            <button class="btn-ghost sm" (click)="cancel.emit(r)">Cancel</button>
          }
        </div>
      } @empty {
        <div class="empty-box"><b>No leave taken yet this year</b></div>
      }
    </div>
  `,
  styleUrl: './leave-balances.scss',
})
export class LeaveBalances {
  summary = input.required<LeaveSummary>();
  canCancel = input(false);
  cancel = output<LeaveRequest>();

  dash(used: number, total: number): string {
    const c = 2 * Math.PI * 26;
    const f = total ? Math.min(1, used / total) : 0;
    return `${(c * f).toFixed(1)} ${c.toFixed(1)}`;
  }

  day(d: string): string {
    return String(new Date(d).getDate());
  }

  mon(d: string): string {
    return new Date(d).toLocaleDateString('en-IN', { month: 'short' });
  }

  range(r: LeaveRequest): string {
    const f = (d: string) => new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
    return r.from === r.to ? f(r.from) : `${f(r.from)} – ${f(r.to)}`;
  }
}
