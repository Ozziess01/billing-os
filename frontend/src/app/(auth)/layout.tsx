export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="grid min-h-screen place-items-center px-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center justify-center gap-2">
          <span className="grid size-7 place-items-center rounded bg-accent font-mono text-sm font-bold text-white">B</span>
          <span className="text-lg font-semibold tracking-tight">BillingOS</span>
        </div>
        {children}
      </div>
    </div>
  );
}
