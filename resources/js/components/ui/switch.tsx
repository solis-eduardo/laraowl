import * as React from "react"
import { cn } from "@/lib/utils"

export interface SwitchProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  checked?: boolean
  onCheckedChange?: (checked: boolean) => void
}

const Switch = React.forwardRef<HTMLButtonElement, SwitchProps>(
  ({ className, checked: controlledChecked, defaultChecked, onCheckedChange, ...props }, ref) => {
    const [uncontrolledChecked, setUncontrolledChecked] = React.useState(defaultChecked || false)
    const isChecked = controlledChecked !== undefined ? controlledChecked : uncontrolledChecked

    const handleToggle = () => {
      const nextChecked = !isChecked
      if (controlledChecked === undefined) {
        setUncontrolledChecked(nextChecked)
      }
      onCheckedChange?.(nextChecked)
    }

    return (
      <button
        type="button"
        role="switch"
        aria-checked={isChecked}
        data-state={isChecked ? "checked" : "unchecked"}
        ref={ref}
        className={cn(
          "inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-emerald-500 data-[state=unchecked]:bg-neutral-300 dark:data-[state=unchecked]:bg-neutral-700",
          className
        )}
        onClick={handleToggle}
        {...props}
      >
        <span
          data-state={isChecked ? "checked" : "unchecked"}
          className={cn(
            "pointer-events-none block h-4 w-4 rounded-full bg-white shadow-lg ring-0 transition-transform data-[state=checked]:translate-x-4 data-[state=unchecked]:translate-x-0 dark:bg-neutral-100"
          )}
        />
      </button>
    )
  }
)
Switch.displayName = "Switch"

export { Switch }
