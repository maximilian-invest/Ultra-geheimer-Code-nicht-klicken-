import { themes } from "../config.js";

export const useTheme = (dark) => dark ? themes.dark : themes.light;
