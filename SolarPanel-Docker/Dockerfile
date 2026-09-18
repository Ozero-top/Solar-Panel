# ===== Stage 1: Go builder =====
FROM golang:1.26-alpine AS builder

# 纯 Go SQLite 驱动不需要 CGO，跨架构零依赖
ENV CGO_ENABLED=0
ENV GOOS=linux
# BuildKit 自动注入 TARGETARCH: amd64 / arm64 / arm
ARG TARGETARCH
ENV GOARCH=${TARGETARCH}

WORKDIR /build

# 先复制依赖文件，利用 Docker cache
COPY go.mod go.sum ./
RUN go mod download

# 复制源码 + 前端（embed 需要）
COPY . .

# 构建
RUN go build -ldflags="-s -w" -o /solarpanel .

# ===== Stage 2: 最小运行时 =====
FROM alpine:3.22

# 证书 + 时区（天气/新闻 API 需要）
RUN apk add --no-cache ca-certificates tzdata \
    && cp /usr/share/zoneinfo/Asia/Shanghai /etc/localtime \
    && echo "Asia/Shanghai" > /etc/timezone

# 创建数据目录 + uploads
RUN mkdir -p /app/data/uploads

WORKDIR /app

# 复制二进制
COPY --from=builder /solarpanel /app/solarpanel

EXPOSE 18080

# 数据卷
VOLUME ["/app/data"]

ENTRYPOINT ["/app/solarpanel"]
