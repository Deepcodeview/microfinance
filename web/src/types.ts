export type CustomerScope = 'all' | 'branch' | 'assigned' | 'own' | 'none'

export interface User {
  id: number
  name: string
  email: string | null
  branch_id: number | null
  roles: string[]
  permissions: string[]
  customer_scope: CustomerScope
  customer_id: number | null
  mfa_enabled: boolean
  mfa_setup_required: boolean
  unread_notifications: number
  settings: { company_name: string; deposits_enabled: boolean; upi_enabled: boolean; business_date: string; self_approval?: boolean }
}

export type PaymentMethod = 'cash' | 'upi' | 'bank_transfer' | 'cheque'

export interface Installment {
  id: string
  number: string
  due_date: string
  principal_due: string
  interest_due: string
  late_fee_due: string
  principal_paid: string
  interest_paid: string
  late_fee_paid: string
  interest_waived: string
  status: 'pending' | 'partial' | 'paid'
  paid_at: string | null
}

export interface LoanPosition {
  principal_outstanding: string
  interest_outstanding: string
  late_fees_outstanding: string
  total_outstanding: string
  due_now: string
  overdue_amount: string
  days_past_due: number
  next_installment: { number: number; due_date: string; amount: string } | null
}

export interface SettlementQuote {
  allowed: boolean
  dues_to_date: string
  future_principal: string
  prepayment_charge: string
  interest_waived: string
  settlement_amount: string
  as_of: string
}

export interface PaymentSummary {
  id: string
  payment_number: string
  amount: string
  /** Savings installments this payment paid for (oldest unpaid first). */
  covers_from?: string | null
  covers_to?: string | null
  covers_count?: number | string | null
  method: PaymentMethod
  purpose: string
  status: string
  business_date: string
  collected_at: string
  collected_by_name: string
  receipt_number: string | null
}

export interface LoanListItem {
  id: string
  loan_number: string
  status: string
  asset_class: string
  dpd: string
  principal: string
  tenure: string
  installment_amount: string
  applied_at: string
  disbursement_date: string | null
  customer_id: string
  customer_name: string
  customer_number: string
  product_name: string
  outstanding: string
  overdue: string
}

export interface Loan {
  id: string
  loan_number: string
  customer_id: string
  customer_name: string
  customer_number: string
  customer_phone: string
  branch_name: string
  product_name: string
  product_code: string
  product_version: string
  principal: string
  tenure: string
  installment_amount: string
  total_interest: string
  processing_fee: string
  tax_on_fee: string
  net_disbursement: string
  purpose: string | null
  status: string
  asset_class: string
  dpd: string
  npa_since: string | null
  written_off_at: string | null
  written_off_amount: string | null
  applied_by: string
  applied_by_name: string
  applied_at: string
  decided_by_name: string | null
  decided_at: string | null
  decision_reason: string | null
  disbursed_by_name: string | null
  disbursed_at: string | null
  disbursement_date: string | null
  disbursement_method: PaymentMethod | null
  disbursed_in_field?: string | number
  /** Set on loans brought in from the old register; opening_collected = what was received before. */
  migrated_at?: string | null
  opening_collected?: string | null
  disbursement_reference: string | null
  first_due_date: string | null
  closed_at: string | null
  closure_type: 'repaid' | 'settled' | null
  terms: LoanTerms
  installments: Installment[]
  position: LoanPosition | null
  settlement_quote: SettlementQuote | null
  payments: PaymentSummary[]
  business_date: string
}

export interface Payment {
  id: string
  payment_number: string
  covers_from?: string | null
  covers_to?: string | null
  covers_count?: number | string | null
  customer_id: string
  customer_name: string
  customer_number: string
  customer_phone: string
  branch_name: string
  account_type: 'loan' | 'deposit'
  loan_id: string | null
  deposit_account_id: string | null
  account_number: string
  purpose: string
  amount: string
  method: PaymentMethod
  reference: string | null
  upi_tr: string | null
  has_proof: boolean
  status: string
  business_date: string
  collected_by_name: string
  collected_at: string
  confirmed_by_name: string | null
  confirmed_at: string | null
  reversed_by_name: string | null
  reversed_at: string | null
  status_reason: string | null
  notes: string | null
  handover_id: string | null
  journal_entry_id: string | null
  reversal_entry_id: string | null
  receipt_number: string | null
  verification_code: string | null
  receipt_issued_at: string | null
  /** Where the collector's phone was when it was recorded (agent app). */
  latitude: string | null
  longitude: string | null
  location_accuracy_m: string | null
  located_at: string | null
  allocations: { component: string; amount: string; installment_number: string | null; due_date: string | null }[]
}

/** One tenure a plan offers, e.g. { value: 45, unit: 'days' }. Loan choices also carry their installment count. */
export interface TenureOption {
  value: number
  unit: 'days' | 'weeks' | 'months' | 'years'
  installments?: number
  /** Annual interest rate (%) for this tenure; absent = the plan's own rate. */
  rate?: string
}

export interface DepositTerms {
  scheme: 'recurring' | 'fixed'
  /** daily = % a year on each day's balance; flat = % once on the total deposited. Missing = daily. */
  interest_method?: 'daily' | 'flat'
  annual_interest_rate: string
  contribution_frequency: 'daily' | 'weekly' | 'monthly'
  min_installment: string
  min_amount: string
  max_amount: string
  tenure_options: TenureOption[]
  /** Only on plan versions saved before tenure choices existed. */
  tenure_months?: number
  premature_withdrawal_allowed: boolean
  premature_penalty_percent: string
  lock_in_days: number
}

export interface PayoutQuote {
  payout_type: 'maturity' | 'premature'
  principal_amount: string
  interest_amount: string
  penalty_amount: string
  net_amount: string
}

export interface Payout extends PayoutQuote {
  id: string
  payout_number: string
  deposit_account_id: string
  deposit_number: string
  customer_name: string
  status: 'requested' | 'approved' | 'rejected' | 'paid'
  request_note: string | null
  requested_by: string
  requested_by_name?: string
  requested_at: string
  decided_by_name?: string | null
  decision_reason: string | null
  paid_at: string | null
  payment_method: PaymentMethod | null
  payment_reference: string | null
}

/** Where a recurring savings account stands: installments owed now and since when. */
export interface ContributionStatus {
  installments_due: number
  missed_installments: number
  installments_paid: number
  installments_expected: number
  unpaid_since: string | null
  last_paid_on: string | null
  amount_due: string
  next_due_date: string
}

export interface ContributionDay {
  date: string
  state: 'paid' | 'paid_late' | 'brought_in' | 'missed' | 'unpaid' | 'due' | 'upcoming'
  paid_on: string | null
}

export interface DepositAccount {
  id: string
  deposit_number: string
  customer_id: string
  customer_name: string
  customer_number: string
  branch_name: string
  product_name: string
  product_code: string
  product_version: string
  scheme: 'recurring' | 'fixed'
  installment_amount: string | null
  deposit_amount: string | null
  opened_on: string
  maturity_date: string
  tenure_value: number | string | null
  tenure_unit: TenureOption["unit"] | null
  status: string
  principal_balance: string
  accrued_interest: string
  interest_paid: string
  last_accrual_date: string | null
  opened_by_name: string
  terms: DepositTerms
  contributions: PaymentSummary[]
  contribution?: ContributionStatus
  calendar?: ContributionDay[]
  accruals: { from_date: string; to_date: string; amount: string }[]
  payouts: Payout[]
  payout_quote: PayoutQuote | null
  payout_blocked_reason: string | null
  deposits_enabled: boolean
}

export interface Handover {
  id: string
  handover_number: string
  agent_id: string
  agent_name: string
  branch_name: string
  business_date: string
  expected_cash: string
  declared_cash: string
  verified_cash: string | null
  difference: string | null
  status: 'submitted' | 'verified' | 'discrepancy' | 'resolved'
  agent_note: string | null
  submitted_at: string
  verified_by_name: string | null
  verified_at: string | null
  verifier_note: string | null
  resolution: string | null
  resolution_note: string | null
  resolved_by_name: string | null
  payment_count?: string
  payments?: { id: string; payment_number: string; amount: string; status: string; business_date: string; customer_name: string }[]
  /** Floats given to the agent and loans they paid out, settled by this handover. */
  movements?: { id: string; movement_number: string; type: 'float_issued' | 'field_disbursement'; amount: string; business_date: string; loan_number: string | null }[]
}

export interface Branch {
  id: string
  code: string
  name: string
}

export interface Agent {
  id: string
  name: string
  branch_id: string
  branch_name: string
}

export type CustomerStatus = 'pending' | 'active' | 'inactive' | 'blocked'

export interface CustomerListItem {
  id: string
  customer_number: string
  full_name: string
  phone: string
  email?: string | null
  address_line?: string
  city: string
  state?: string
  pincode?: string
  occupation?: string | null
  status: CustomerStatus
  source?: 'staff' | 'self'
  registration_note?: string | null
  created_at: string
  branch_name: string
  agent_name: string | null
}

export interface Assignment {
  id: string
  agent_user_id: string
  agent_name: string
  valid_from: string
  valid_to: string | null
  assigned_by_name: string
}

export interface Customer {
  id: string
  customer_number: string
  branch_id: string
  branch_name: string
  full_name: string
  phone: string
  alt_phone: string | null
  email: string | null
  date_of_birth: string | null
  gender: 'male' | 'female' | 'other' | null
  address_line: string
  city: string
  state: string
  pincode: string
  occupation: string | null
  monthly_income: string | null
  status: CustomerStatus
  source: 'staff' | 'self'
  registration_note: string | null
  created_by_name: string
  created_at: string
  updated_at: string
  assignments: Assignment[]
  portal_email: string | null
  portal_status: string | null
  latitude: string | null
  longitude: string | null
  location_accuracy_m: number | null
  location_set_at: string | null
  loans: CustomerLoan[]
  deposits: CustomerDeposit[]
}

export interface CustomerLoan {
  id: string
  loan_number: string
  status: string
  principal: string
  installment_amount: string | null
  product_name: string
  frequency: LoanTerms['repayment_frequency']
  outstanding: string
  overdue: string
  next_due_date: string | null
  installments_total: string
  installments_paid: string
}

export interface CustomerDeposit {
  id: string
  deposit_number: string
  status: string
  scheme: 'recurring' | 'fixed'
  installment_amount: string | null
  deposit_amount: string | null
  opened_on: string
  principal_balance: string
  accrued_interest: string
  maturity_date: string
  tenure_value?: number | string | null
  tenure_unit?: TenureOption["unit"] | null
  product_name: string
  frequency: DepositTerms['contribution_frequency'] | null
  /** Active recurring accounts only. */
  installments_due?: number
  missed_installments?: number
  unpaid_since?: string | null
  amount_due?: string
  next_due_date?: string
}

export type AllocationComponent = 'late_fee' | 'fees' | 'interest' | 'principal'

/** Money and percentages are decimal strings end to end — never JS numbers. */
export interface LoanTerms {
  interest_method: 'reducing_balance' | 'flat'
  annual_interest_rate: string
  repayment_frequency: 'daily' | 'weekly' | 'fortnightly' | 'monthly' | 'quarterly' | 'yearly'
  min_amount: string
  max_amount: string
  min_tenure: number
  max_tenure: number
  /** Fixed choices; empty or missing = any tenure from min_tenure to max_tenure. */
  tenure_options?: TenureOption[]
  processing_fee_percent: string
  tax_on_fees_percent: string
  late_fee_type: 'fixed' | 'percent_of_overdue'
  late_fee_value: string
  grace_period_days: number
  prepayment_allowed: boolean
  prepayment_charge_percent: string
  allocation_order: AllocationComponent[]
  installment_rounding: 'paise' | 'rupee'
  holiday_adjustment?: 'next_business_day' | 'none'
}

export interface LoanProductVersion {
  id: string
  version: string
  terms: LoanTerms
  effective_from: string
  change_reason: string
  created_by_name: string
  created_at: string
}

export interface LoanProduct {
  id: string
  code: string
  name: string
  description: string | null
  status: 'active' | 'inactive'
  updated_at: string
  version_id: string
  version: string
  effective_from: string
  accounts_count: string
  terms: LoanTerms
  versions?: LoanProductVersion[]
}

export interface SchedulePreviewRow {
  number: number
  installment: string
  principal: string
  interest: string
  balance: string
}

export interface LoanPreview {
  principal: string
  tenure: number
  installment_amount: string
  total_interest: string
  total_payable: string
  processing_fee: string
  tax_on_processing_fee: string
  net_disbursement: string
  schedule: SchedulePreviewRow[]
}

export interface PageMeta {
  page: number
  per_page: number
  total: number
  total_pages: number
}

/** Plans as the customer app shows them before sign-in (GET /public/catalog). */
export type PublicLoanTerms = Omit<LoanTerms, 'allocation_order' | 'installment_rounding' | 'holiday_adjustment'>
export interface PublicPlan<T> {
  id: string
  code: string
  name: string
  description: string | null
  terms: T
}
export interface Catalog {
  company_name: string
  deposits_enabled: boolean
  branches: { id: string; name: string }[]
  loan_products: PublicPlan<PublicLoanTerms>[]
  deposit_products: PublicPlan<DepositTerms>[]
}

/** A loan or savings application made in the customer app. */
export interface CustomerApplication {
  id: string
  application_number: string
  customer_id: string
  customer_name: string
  customer_number: string
  customer_phone: string
  customer_status: CustomerStatus
  branch_name: string
  type: 'loan' | 'savings'
  product_id: string
  product_name: string | null
  amount: string
  tenure: string | null
  tenure_unit: TenureOption['unit'] | null
  purpose: string | null
  status: 'submitted' | 'accepted' | 'rejected' | 'cancelled'
  decided_by_name: string | null
  decided_at: string | null
  decision_note: string | null
  loan_id: string | null
  loan_number: string | null
  loan_status: string | null
  deposit_account_id: string | null
  deposit_number: string | null
  created_at: string
}
