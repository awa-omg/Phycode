package theme

import (
	"fmt"
	"math"
	"strconv"
	"strings"
)

type rgb struct{ r, g, b float64 }

func parse(hex string) rgb {
	hex = strings.TrimPrefix(hex, "#")
	if len(hex) != 6 {
		return rgb{}
	}
	n, err := strconv.ParseUint(hex, 16, 32)
	if err != nil {
		return rgb{}
	}
	return rgb{float64(n >> 16 & 0xff), float64(n >> 8 & 0xff), float64(n & 0xff)}
}

func (c rgb) hex() string {
	clamp := func(v float64) int { return int(math.Round(math.Min(math.Max(v, 0), 255))) }
	return fmt.Sprintf("#%02x%02x%02x", clamp(c.r), clamp(c.g), clamp(c.b))
}

func mix(a, b string, t float64) string {
	x, y := parse(a), parse(b)
	return rgb{x.r + (y.r-x.r)*t, x.g + (y.g-x.g)*t, x.b + (y.b-x.b)*t}.hex()
}

func luminance(hex string) float64 {
	c := parse(hex)
	lin := func(v float64) float64 {
		v /= 255
		if v <= 0.03928 {
			return v / 12.92
		}
		return math.Pow((v+0.055)/1.055, 2.4)
	}
	return 0.2126*lin(c.r) + 0.7152*lin(c.g) + 0.0722*lin(c.b)
}

func Contrast(a, b string) float64 {
	la, lb := luminance(a), luminance(b)
	if la < lb {
		la, lb = lb, la
	}
	return (la + 0.05) / (lb + 0.05)
}
