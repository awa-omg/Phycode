PORTNAME=	phycode
DISTVERSION=	0.1.0
CATEGORIES=	devel
MASTER_SITES=	https://github.com/awa-omg/Phycode/releases/download/v${DISTVERSION}/
EXTRACT_SUFX=	.phar

MAINTAINER=	katuharina@gmail.com
COMMENT=	Coding agent for local models, written in PHP
WWW=		https://github.com/awa-omg/Phycode

LICENSE=	APACHE20

RUN_DEPENDS=	bash:shells/bash \
		git:devel/git

USES=		php:cli
USE_PHP=	curl dom filter mbstring openssl pcntl phar posix

NO_ARCH=	yes
NO_BUILD=	yes

PLIST_FILES=	bin/phycode

do-extract:
	${CP} ${DISTDIR}/${DISTFILES} ${WRKDIR}/phycode

do-install:
	${INSTALL_SCRIPT} ${WRKDIR}/phycode ${STAGEDIR}${PREFIX}/bin/phycode

.include <bsd.port.mk>
