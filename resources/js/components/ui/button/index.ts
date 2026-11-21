import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg text-sm font-medium transition-colors disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground hover:bg-[var(--primary-hover)] active:bg-[var(--primary-hover)]",
        destructive:
          "bg-destructive text-[var(--destructive-foreground)] hover:bg-destructive/90 active:bg-destructive/85 focus-visible:ring-destructive",
        outline:
          "border border-input bg-card text-foreground hover:bg-muted active:bg-accent active:text-accent-foreground",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-accent active:bg-accent active:text-accent-foreground",
        ghost:
          "text-foreground hover:bg-accent hover:text-accent-foreground active:bg-muted",
        link: "text-[var(--link)] underline-offset-4 hover:underline",
      },
      size: {
        "default": "h-[var(--control-height)] px-4 py-2 has-[>svg]:px-3",
        "xs": "h-6 gap-1 rounded-lg px-2 text-xs has-[>svg]:px-1.5 [&_svg:not([class*='size-'])]:size-3",
        "sm": "h-8 rounded-lg gap-2 px-3 has-[>svg]:px-2.5",
        "lg": "h-[var(--control-height)] rounded-lg px-6 has-[>svg]:px-4",
        "icon": "size-[var(--control-height)]",
        "icon-xs": "size-6 rounded-lg [&_svg:not([class*='size-'])]:size-3",
        "icon-sm": "size-8",
        "icon-lg": "size-[var(--control-height)]",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
