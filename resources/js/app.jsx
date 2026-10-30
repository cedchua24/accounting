import React, { useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
    ArrowDownLeft, ArrowRight, ArrowUpRight, Banknote, Bell, BookOpen,
    Building2, Check, ChevronDown, CircleDollarSign, CreditCard, FileText,
    LayoutDashboard, Menu, MoreHorizontal, Plus, ReceiptText, Search,
    Settings, Users, WalletCards, X,
} from 'lucide-react';
import './bootstrap';

const navItems = [
    { label: 'Overview', icon: LayoutDashboard },
    { label: 'Transactions', icon: ReceiptText },
    { label: 'Invoices', icon: FileText, badge: 6 },
    { label: 'Accounts', icon: WalletCards },
    { label: 'Contacts', icon: Users },
    { label: 'Journal', icon: BookOpen },
];

const metrics = [
    { label: 'Total balance', value: '₱2,846,320.50', change: '+12.5%', direction: 'up', note: 'vs last month', icon: WalletCards },
    { label: 'Money in', value: '₱684,250.00', change: '+8.2%', direction: 'up', note: 'this month', icon: ArrowDownLeft },
    { label: 'Money out', value: '₱392,840.00', change: '-3.1%', direction: 'down', note: 'this month', icon: ArrowUpRight },
    { label: 'Outstanding', value: '₱186,420.00', change: '6 invoices', direction: 'neutral', note: 'awaiting payment', icon: CircleDollarSign },
];

const transactions = [
    { id: 'TXN-2841', name: 'Northstar Retail Co.', category: 'Sales income', date: 'Oct 11, 2026', amount: '+₱84,200.00', type: 'income', status: 'Completed', icon: Building2 },
    { id: 'TXN-2840', name: 'Manila Electric Company', category: 'Utilities', date: 'Oct 10, 2026', amount: '-₱18,640.00', type: 'expense', status: 'Completed', icon: Banknote },
    { id: 'TXN-2839', name: 'Pacific Office Supply', category: 'Office supplies', date: 'Oct 10, 2026', amount: '-₱7,845.50', type: 'expense', status: 'Pending', icon: ReceiptText },
    { id: 'TXN-2838', name: 'Brightworks Studio', category: 'Sales income', date: 'Oct 9, 2026', amount: '+₱42,500.00', type: 'income', status: 'Completed', icon: Building2 },
    { id: 'TXN-2837', name: 'Cloudbase Systems', category: 'Software', date: 'Oct 8, 2026', amount: '-₱12,990.00', type: 'expense', status: 'Completed', icon: CreditCard },
];

const accounts = [
    { name: 'Operating account', institution: 'BDO ···· 4821', amount: '₱1,482,200.50', tone: 'navy' },
    { name: 'Business savings', institution: 'BPI ···· 1290', amount: '₱964,120.00', tone: 'green' },
    { name: 'Petty cash', institution: 'Main branch', amount: '₱400,000.00', tone: 'sand' },
];

const chartData = {
    '7 days': [46, 58, 49, 66, 61, 78, 72],
    '30 days': [34, 45, 39, 55, 51, 67, 59, 74, 69, 81, 76, 86],
    '12 months': [28, 36, 42, 38, 54, 49, 62, 68, 64, 76, 82, 88],
};

function Sparkline({ values }) {
    const points = values.map((value, index) => `${(index / (values.length - 1)) * 100},${100 - value}`).join(' ');
    const area = `0,100 ${points} 100,100`;
    return (
        <svg className="chart-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-label="Cash flow trend">
            <defs><linearGradient id="chartFill" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stopColor="#2f6f57" stopOpacity=".24" /><stop offset="100%" stopColor="#2f6f57" stopOpacity="0" /></linearGradient></defs>
            <line x1="0" y1="25" x2="100" y2="25" className="grid-line" /><line x1="0" y1="50" x2="100" y2="50" className="grid-line" /><line x1="0" y1="75" x2="100" y2="75" className="grid-line" />
            <polygon points={area} fill="url(#chartFill)" /><polyline points={points} fill="none" className="chart-line" vectorEffect="non-scaling-stroke" /><circle cx="100" cy={100 - values.at(-1)} r="1.8" className="chart-dot" />
        </svg>
    );
}

function App() {
    const [activeNav, setActiveNav] = useState('Overview');
    const [period, setPeriod] = useState('30 days');
    const [query, setQuery] = useState('');
    const [mobileOpen, setMobileOpen] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);
    const [toast, setToast] = useState('');
    const filteredTransactions = useMemo(() => transactions.filter((item) => `${item.name} ${item.category} ${item.id}`.toLowerCase().includes(query.toLowerCase())), [query]);
    const showToast = (message) => { setToast(message); window.setTimeout(() => setToast(''), 2600); };
    const chooseNav = (label) => { setActiveNav(label); setMobileOpen(false); if (label !== 'Overview') showToast(`${label} workspace is ready for your next requirements.`); };

    return (
        <div className="app-shell">
            <aside className={`sidebar ${mobileOpen ? 'is-open' : ''}`}>
                <div className="brand"><span className="brand-mark"><span>L</span></span><span className="brand-name">ledgerly</span></div>
                <nav className="primary-nav" aria-label="Main navigation"><p className="nav-label">Workspace</p>
                    {navItems.map(({ label, icon: Icon, badge }) => <button key={label} className={`nav-item ${activeNav === label ? 'active' : ''}`} onClick={() => chooseNav(label)}><Icon size={19} strokeWidth={1.8} /><span>{label}</span>{badge && <span className="nav-badge">{badge}</span>}</button>)}
                </nav>
                <div className="sidebar-bottom">
                    <button className="nav-item" onClick={() => showToast('Settings will be configured with your preferences.')}><Settings size={19} strokeWidth={1.8} /><span>Settings</span></button>
                    <div className="help-card"><span className="help-icon">?</span><div><strong>Need a hand?</strong><p>View the portal guide</p></div><ArrowRight size={17} /></div>
                    <div className="user-card"><span className="avatar">CC</span><div><strong>Ced Chua</strong><p>Administrator</p></div><MoreHorizontal size={18} /></div>
                </div>
            </aside>
            {mobileOpen && <button className="scrim" aria-label="Close menu" onClick={() => setMobileOpen(false)} />}
            <main className="main-content">
                <header className="topbar">
                    <button className="mobile-menu" aria-label="Open menu" onClick={() => setMobileOpen(true)}><Menu size={22} /></button>
                    <div className="search-wrap"><Search size={18} /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search transactions, invoices, contacts..." aria-label="Search" /><kbd>⌘ K</kbd></div>
                    <div className="top-actions"><button className="icon-button" aria-label="Notifications" onClick={() => showToast('You are all caught up.')}><Bell size={20} /></button><button className="branch-button" onClick={() => showToast('Branch selector will use your live branch list.')}><span className="branch-symbol">M</span><span>Main branch</span><ChevronDown size={15} /></button></div>
                </header>
                <div className="content-wrap">
                    <section className="page-heading"><div><p className="eyebrow">Sunday, October 11</p><h1>Good morning, Ced.</h1><p>Here’s what’s happening with your business today.</p></div><button className="primary-button" onClick={() => setModalOpen(true)}><Plus size={18} /> New transaction</button></section>
                    <section className="metrics-grid" aria-label="Financial summary">
                        {metrics.map(({ label, value, change, direction, note, icon: Icon }) => <article className="metric-card" key={label}><div className="metric-top"><span>{label}</span><span className="metric-icon"><Icon size={18} /></span></div><strong>{value}</strong><p><span className={`change ${direction}`}>{direction === 'up' ? '↗' : direction === 'down' ? '↘' : ''} {change}</span> {note}</p></article>)}
                    </section>
                    <section className="dashboard-grid">
                        <article className="panel cashflow-panel">
                            <div className="panel-heading"><div><h2>Cash flow</h2><p>Income and expenses over time</p></div><div className="period-tabs">{Object.keys(chartData).map((item) => <button key={item} className={period === item ? 'active' : ''} onClick={() => setPeriod(item)}>{item}</button>)}</div></div>
                            <div className="cashflow-summary"><div><span>Net cash flow</span><strong>₱291,410.00</strong></div><span className="positive-pill">↗ 18.4%</span></div>
                            <div className="chart-wrap"><Sparkline values={chartData[period]} /></div>
                            <div className="chart-labels"><span>Sep 12</span><span>Sep 18</span><span>Sep 24</span><span>Sep 30</span><span>Oct 6</span><span>Oct 11</span></div>
                            <div className="chart-legend"><span><i className="legend-income" /> Income ₱684,250</span><span><i className="legend-expense" /> Expenses ₱392,840</span></div>
                        </article>
                        <article className="panel accounts-panel">
                            <div className="panel-heading"><div><h2>Accounts</h2><p>Current available balances</p></div><button className="text-button" onClick={() => chooseNav('Accounts')}>View all <ArrowRight size={15} /></button></div>
                            <div className="accounts-list">{accounts.map((account) => <div className="account-row" key={account.name}><span className={`account-icon ${account.tone}`}><CreditCard size={18} /></span><div><strong>{account.name}</strong><p>{account.institution}</p></div><b>{account.amount}</b></div>)}</div>
                            <button className="add-account" onClick={() => showToast('Account setup will connect to the financial accounts API.')}><Plus size={17} /> Add financial account</button>
                        </article>
                    </section>
                    <section className="panel transactions-panel">
                        <div className="panel-heading"><div><h2>Recent transactions</h2><p>{query ? `${filteredTransactions.length} matching results` : 'Your latest account activity'}</p></div><button className="text-button" onClick={() => chooseNav('Transactions')}>View all <ArrowRight size={15} /></button></div>
                        <div className="transaction-list">{filteredTransactions.map((transaction) => { const Icon = transaction.icon; return <div className="transaction-row" key={transaction.id}><span className={`transaction-icon ${transaction.type}`}><Icon size={18} /></span><div className="transaction-name"><strong>{transaction.name}</strong><p>{transaction.id}</p></div><div className="transaction-category"><span>{transaction.category}</span><p>{transaction.date}</p></div><span className={`status ${transaction.status.toLowerCase()}`}>{transaction.status}</span><strong className={`transaction-amount ${transaction.type}`}>{transaction.amount}</strong><button className="row-menu" aria-label={`More options for ${transaction.name}`}><MoreHorizontal size={18} /></button></div>; })}
                            {!filteredTransactions.length && <div className="empty-state"><Search size={26} /><strong>No transactions found</strong><p>Try a name, category, or transaction number.</p></div>}
                        </div>
                    </section>
                </div>
            </main>
            {modalOpen && <div className="modal-backdrop" role="presentation" onMouseDown={() => setModalOpen(false)}><section className="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title" onMouseDown={(event) => event.stopPropagation()}><button className="modal-close" aria-label="Close" onClick={() => setModalOpen(false)}><X size={19} /></button><span className="modal-icon"><Plus size={22} /></span><h2 id="modal-title">New transaction</h2><p>Choose what you’d like to record. We’ll connect these actions to your Laravel workflows next.</p><div className="transaction-options">{[['Money in', 'Record a customer payment', ArrowDownLeft], ['Money out', 'Pay a bill or expense', ArrowUpRight], ['Transfer', 'Move funds between accounts', ArrowRight]].map(([title, subtitle, Icon]) => <button key={title} onClick={() => { setModalOpen(false); showToast(`${title} flow selected.`); }}><span><Icon size={18} /></span><div><strong>{title}</strong><p>{subtitle}</p></div><ArrowRight size={17} /></button>)}</div></section></div>}
            {toast && <div className="toast"><span><Check size={15} /></span>{toast}</div>}
        </div>
    );
}

createRoot(document.getElementById('app')).render(<App />);
